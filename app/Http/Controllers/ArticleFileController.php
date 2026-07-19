<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MediaUploadSession;
use App\Services\PrimaryManuscriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ArticleFileController extends Controller
{
    public function destroyAdditionalManuscriptFile(Request $request, Article $article, ArticleFile $file): JsonResponse
    {
        if ((int) $file->article_id !== (int) $article->id
            || $file->file_type !== ArticleFile::ADDITIONAL_MANUSCRIPT_FILE
            || $file->article_version_id
            || !$request->user()
            || ((int) $article->user_id !== (int) $request->user()->id && !$this->isAuthorRecord($request->user(), $article))) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $status = ArticleStatus::normalize($article->status);
        if ($status !== ArticleStatus::DRAFT && !ArticleStatus::isRevisionRequired($status)) {
            return response()->json(['message' => 'Historical submission files cannot be removed.'], 422);
        }

        $file->delete();

        return response()->json(['message' => 'Additional manuscript file removed.']);
    }

    public function destroyPrimaryManuscript(Request $request, Article $article, ArticleFile $file): JsonResponse
    {
        if (!$request->user()
            || ((int) $article->user_id !== (int) $request->user()->id && !$this->isAuthorRecord($request->user(), $article))) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $result = app(PrimaryManuscriptService::class)->removeDraft($article, $file);

        return response()->json([
            'message' => $result['storage_warning']
                ? 'The manuscript was removed. Storage cleanup will be retried.'
                : 'The manuscript was removed from this draft submission.',
            'storage_cleanup_pending' => $result['storage_warning'],
        ]);
    }

    public function store(Request $request, int $articleId): JsonResponse
    {
        return response()->json([
            'message' => 'Raw browser uploads are disabled for article files. Use the direct S3 upload-session flow.',
        ], 410);
    }

    public function download(Request $request, int $fileId)
    {
        $file = ArticleFile::with(['article', 'uploader:id,name'])->findOrFail($fileId);

        if (!$this->canAccess($request->user('sanctum'), $file)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        if (($file->scan_status ?? 'clean') !== 'clean') {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        if (($file->disk ?? 'public') !== 'public') {
            $key = $file->storage_key ?: $file->file_path;
            if (!$key) {
                return response()->json(['message' => 'The requested file is not available.'], 404);
            }

             return redirect()->away(
                 Storage::disk($file->disk)->temporaryUrl($key, now()->addMinutes(config('media_uploads.download_url_ttl_minutes')), [
                     'ResponseContentDisposition' => 'attachment; filename="' . addslashes($file->safe_original_name ?: $file->original_name) . '"',
                     'ResponseContentType' => $file->mime_type ?: 'application/octet-stream',
                 ])
             );
        }

        $relativePath = str_replace('storage/', '', $file->file_path);
        if (!Storage::disk('public')->exists($relativePath)) {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        return response()->file(Storage::disk('public')->path($relativePath), [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'attachment; filename="' . $file->original_name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function createPendingDirectUploadFile(Article $article, MediaUploadSession $upload, array $purposeConfig): ArticleFile
    {
        $metadata = $upload->metadata ?: [];
        $isAdditionalManuscriptFile = $purposeConfig['article_file_type'] === ArticleFile::ADDITIONAL_MANUSCRIPT_FILE;

        if ($purposeConfig['article_file_type'] === ArticleFile::MANUSCRIPT) {
            return DB::transaction(function () use ($article, $upload, $purposeConfig, $metadata) {
                Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
                app(PrimaryManuscriptService::class)->assertDraftSlotAvailable($article, $upload->id);
                return $this->createPendingFile($article, $upload, $purposeConfig, $metadata, null);
            });
        }

        return $this->createPendingFile($article, $upload, $purposeConfig, $metadata, $isAdditionalManuscriptFile
            ? null
            : ($metadata['article_version_id'] ?? $article->versions()->latest('version_number')->value('id')));
    }

    private function createPendingFile(Article $article, MediaUploadSession $upload, array $purposeConfig, array $metadata, ?int $versionId): ArticleFile
    {

        return ArticleFile::firstOrCreate(['media_upload_session_id' => $upload->id], [
            'article_id' => $article->id,
            'article_version_id' => $versionId,
            'uploaded_by' => $upload->user_id,
            'assignment_type' => $metadata['assignment_type'] ?? null,
            'assignment_id' => $metadata['assignment_id'] ?? null,
            'file_type' => $purposeConfig['article_file_type'],
            'file_title' => $metadata['file_title'] ?? null,
            'visibility' => $this->defaultVisibility($purposeConfig['article_file_type']),
            'disk' => $upload->disk,
            'file_path' => $upload->s3_incoming_key,
            'storage_key' => $upload->s3_incoming_key,
            'original_name' => $upload->safe_display_filename,
            'safe_original_name' => $upload->safe_display_filename,
            'mime_type' => $upload->declared_mime_type ?: 'application/octet-stream',
            'size' => $upload->expected_size_bytes,
            'checksum_sha256' => null,
            'scan_status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
            'metadata' => [
                'upload_session_id' => $upload->id,
                'direct_s3_upload' => true,
            ],
        ]);
    }

    public function createCleanDirectUploadFile(Article $article, MediaUploadSession $upload, array $purposeConfig, array $extra = []): ArticleFile
    {
        $isAdditionalManuscriptFile = $purposeConfig['article_file_type'] === ArticleFile::ADDITIONAL_MANUSCRIPT_FILE;
        $isPrimaryManuscript = $purposeConfig['article_file_type'] === ArticleFile::MANUSCRIPT;
        $values = [
            'article_id' => $article->id,
            'article_version_id' => array_key_exists('article_version_id', $extra)
                ? $extra['article_version_id']
                : (($isAdditionalManuscriptFile || $isPrimaryManuscript) ? null : $article->versions()->latest('version_number')->value('id')),
            'uploaded_by' => $upload->user_id,
            'assignment_type' => $extra['assignment_type'] ?? ($upload->metadata['assignment_type'] ?? null),
            'assignment_id' => $extra['assignment_id'] ?? ($upload->metadata['assignment_id'] ?? null),
            'file_type' => $purposeConfig['article_file_type'],
            'file_title' => $extra['file_title'] ?? ($upload->metadata['file_title'] ?? null),
            'visibility' => $this->defaultVisibility($purposeConfig['article_file_type']),
            'disk' => $upload->disk,
            'file_path' => $upload->s3_clean_key,
            'storage_key' => $upload->s3_clean_key,
            'original_name' => $upload->safe_display_filename,
            'safe_original_name' => $upload->safe_display_filename,
            'mime_type' => $upload->detected_mime_type ?: $upload->declared_mime_type ?: 'application/octet-stream',
            'size' => $upload->expected_size_bytes,
            'checksum_sha256' => $upload->checksum_sha256,
            'scan_status' => 'clean',
            'scan_engine' => $upload->scan_engine,
            'scanned_at' => $upload->scanned_at,
            'metadata' => array_merge([
                'upload_session_id' => $upload->id,
                'direct_s3_upload' => true,
            ], $extra['metadata'] ?? []),
        ];

        [$file, $alreadyAttached] = DB::transaction(function () use ($article, $upload, $values, $isPrimaryManuscript) {
            Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            if ($isPrimaryManuscript) app(PrimaryManuscriptService::class)->assertDraftSlotAvailable($article, $upload->id);
            $file = ArticleFile::firstOrNew(['media_upload_session_id' => $upload->id]);
            $alreadyAttached = $file->exists;
            $file->fill($values)->save();
            return [$file, $alreadyAttached];
        });

        Log::info($alreadyAttached ? 'upload.attach_duplicate_prevented' : 'upload.attached', [
            'user_id' => $upload->user_id,
            'article_id' => $article->id,
            'article_version_id' => $file->article_version_id,
            'upload_session_id' => $upload->id,
            'article_file_id' => $file->id,
            'purpose' => $upload->purpose,
        ]);

        return $file;
    }

    public function serializeFile(ArticleFile $file): array
    {
        $file->loadMissing('uploader:id,name');

        return [
            'id' => $file->id,
            'article_id' => $file->article_id,
            'article_version_id' => $file->article_version_id,
            'source_asset_id' => $file->source_asset_id,
            'file_type' => $file->file_type,
            'file_title' => $file->file_title,
            'visibility' => $file->visibility,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'scan_status' => $file->scan_status ?? 'clean',
            'available' => ($file->scan_status ?? 'clean') === 'clean',
            'uploader' => $file->uploader ? [
                'id' => $file->uploader->id,
                'name' => $file->uploader->name,
            ] : null,
            'created_at' => $file->created_at,
            'download_url' => ($file->scan_status ?? 'clean') === 'clean' ? "/api/articles/files/{$file->id}/download" : null,
            'assignment_type' => $file->assignment_type,
            'assignment_id' => $file->assignment_id,
            'publication_visibility' => $file->metadata['publication_visibility'] ?? [
                'show_on_article' => false,
                'show_in_downloads' => false,
                'include_in_package' => false,
            ],
        ];
    }

    public function filterVisibleFiles($user, iterable $files): array
    {
        return collect($files)
            ->filter(fn (ArticleFile $file) => $this->isWorkflowReady($file))
            ->filter(fn (ArticleFile $file) => $this->canAccess($user, $file))
            ->map(fn (ArticleFile $file) => $this->serializeFile($file))
            ->values()
            ->all();
    }

    public function isWorkflowReady(ArticleFile $file): bool
    {
        return ($file->scan_status ?? 'clean') === 'clean'
            && (bool) ($file->storage_key ?: $file->file_path);
    }

    public function canAccess($user, ArticleFile $file): bool
    {
        $article = $file->article;

        if (!$article) {
            return false;
        }

        if ($this->isGlobal($user)) {
            return true;
        }

        if (!$user) {
            $isPublicationVisible = (bool) data_get($file->metadata, 'publication_visibility.show_on_article')
                || (bool) data_get($file->metadata, 'publication_visibility.show_in_downloads');
            $isActivePublicationPdf = $file->file_type === ArticleFile::PUBLICATION_PDF
                && ($file->storage_key ?: $file->file_path) === $article->pdf_path;

            return ArticleStatus::normalize($article->status) === ArticleStatus::PUBLISHED
                && ($isPublicationVisible || $isActivePublicationPdf || $file->file_type === ArticleFile::SUPPLEMENTARY);
        }

        if ($article->user_id === $user->id || $this->isAuthorRecord($user, $article)) {
            return in_array($file->file_type, [
                ArticleFile::MANUSCRIPT,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::COPY_EDITED_FILE,
                ArticleFile::PROOF_FILE,
                ArticleFile::PUBLICATION_PDF,
                ArticleFile::ANNOTATED_MANUSCRIPT,
                ArticleFile::REVIEWED_MANUSCRIPT,
                ArticleFile::REVISION_RESPONSE,
                ArticleFile::ADDITIONAL_MANUSCRIPT_FILE,
            ], true);
        }

        if ($this->isAssignedToMagazine($user, $article->magazine_id, ['editor'])
            || ($user->hasRole('sub_editor') && $this->hasSubEditorAssignment($user, $article))) {
            return true;
        }

        if ($this->isAssignedToMagazine($user, $article->magazine_id, ['publisher'])) {
            if ($this->isActiveAcceptedFile($file)) {
                return true;
            }
            return in_array($file->file_type, [
                ArticleFile::COPY_EDITED_FILE,
                ArticleFile::PROOF_FILE,
                ArticleFile::PUBLICATION_PDF,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::MANUSCRIPT,
            ], true);
        }

        if ($this->hasReviewerAssignment($user, $article)) {
            return in_array($file->file_type, [
                ArticleFile::MANUSCRIPT,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::REVIEWED_MANUSCRIPT,
            ], true);
        }

        if ($this->hasProductionAssignment($user, $article, null, 'copy_editor')) {
            if ($file->file_type === ArticleFile::COPY_EDITED_FILE && (int) $file->uploaded_by === (int) $user->id) {
                return true;
            }

            return $this->isActiveAcceptedFile($file);
        }

        return false;
    }

    private function isActiveAcceptedFile(ArticleFile $file): bool
    {
        return DB::table('article_accepted_file_set_items as accepted_items')
            ->join('article_accepted_file_sets as accepted_sets', 'accepted_sets.id', '=', 'accepted_items.accepted_file_set_id')
            ->where('accepted_items.article_file_id', $file->id)
            ->where('accepted_sets.article_id', $file->article_id)
            ->whereNull('accepted_sets.superseded_at')
            ->exists();
    }

    public function canUploadForDirectSession($user, ?Article $article, string $fileType, ?string $assignmentType, ?int $assignmentId): bool
    {
        return $this->canUpload($user, $article, $fileType, $assignmentType, $assignmentId);
    }

    private function canUpload($user, ?Article $article, string $fileType, ?string $assignmentType, ?int $assignmentId): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        if (!$article) {
            if ($fileType === ArticleFile::MANUSCRIPT) {
                return $user && (
                    $user->hasPermission('articles.create')
                    || $user->hasRole(['author', 'editor'])
                    || $this->isGlobal($user)
                );
            }
            if ($fileType === ArticleFile::PUBLICATION_PDF) {
                return $user && ($user->hasRole(['publisher', 'editor']) || $this->isGlobal($user));
            }
            if ($fileType === ArticleFile::ADDITIONAL_MANUSCRIPT_FILE) {
                return $user && ($user->hasPermission('articles.create') || $user->hasRole(['author', 'editor']));
            }

            return false;
        }

        if ($fileType === ArticleFile::SUPPLEMENTARY) {
            $status = ArticleStatus::normalize($article->status);
            $isAllowedStatus = $status === ArticleStatus::DRAFT
                || $status === ArticleStatus::SUBMITTED
                || $status === ArticleStatus::RESUBMITTED
                || ArticleStatus::isEditableStatus($status);

            if (!$isAllowedStatus) {
                return false;
            }

            return $user && ($article->user_id === $user->id || $this->isAuthorRecord($user, $article));
        }

        if ($fileType === ArticleFile::ADDITIONAL_MANUSCRIPT_FILE) {
            $status = ArticleStatus::normalize($article->status);

            return $user
                && ($article->user_id === $user->id || $this->isAuthorRecord($user, $article))
                && ($status === ArticleStatus::DRAFT || ArticleStatus::isRevisionRequired($status));
        }

        if (
            $fileType === ArticleFile::MANUSCRIPT
            && !ArticleStatus::isEditableStatus($article->status)
        ) {
            return false;
        }

        return match ($fileType) {
            ArticleFile::MANUSCRIPT => $user && $user->can('update', $article),
            ArticleFile::PLAGIARISM_REPORT => $this->isAssignedToMagazine($user, $article->magazine_id, ['editor']),
            ArticleFile::ANNOTATED_MANUSCRIPT => $this->hasSubEditorAssignment($user, $article, $assignmentId),
            ArticleFile::REVIEWED_MANUSCRIPT => $this->hasReviewerAssignment($user, $article, $assignmentId),
            ArticleFile::REVISION_RESPONSE => $user
                && ($article->user_id === $user->id || $this->isAuthorRecord($user, $article))
                && ArticleStatus::isRevisionRequired($article->status),
            ArticleFile::COPY_EDITED_FILE => $this->hasProductionAssignment($user, $article, $assignmentId, 'copy_editor'),
            ArticleFile::PROOF_FILE => false,
            ArticleFile::PUBLICATION_PDF => $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher']),
            default => false,
        };
    }

    private function defaultVisibility(string $fileType): string
    {
        return match ($fileType) {
            ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY, ArticleFile::REVISION_RESPONSE, ArticleFile::ADDITIONAL_MANUSCRIPT_FILE, ArticleFile::PROOF_FILE, ArticleFile::PUBLICATION_PDF => 'author_visible',
            ArticleFile::REVIEWED_MANUSCRIPT => 'reviewer_editor',
            default => 'workflow',
        };
    }

    private function isGlobal($user): bool
    {
        return $user && ($user->hasRole('super_admin') || $user->hasRole('admin'));
    }

    private function isAuthorRecord($user, Article $article): bool
    {
        return DB::table('article_author')
            ->where('article_id', $article->id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('co_author_email', $user->email);
            })
            ->exists();
    }

    private function isAssignedToMagazine($user, int $magazineId, array $roles): bool
    {
        if (!$user) {
            return false;
        }

        if (in_array('editor', $roles, true) && $user->isPublicationEditor()) {
            $publicationType = Magazine::query()->whereKey($magazineId)->value('publication_type');
            if (!$publicationType || !in_array($publicationType, $user->editorPublicationTypes(), true)) {
                return false;
            }
        }

        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->unique()
            ->values()
            ->all();

        return DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where('magazine_id', $magazineId)
            ->where(function ($query) use ($normalizedRoles) {
                $query->whereIn('role', $normalizedRoles)
                    ->orWhereNull('role');
            })
            ->exists();
    }

    private function hasSubEditorAssignment($user, Article $article, ?int $assignmentId = null): bool
    {
        if (!$user) {
            return false;
        }

        return DB::table('sub_editor_assignments')
            ->where('article_id', $article->id)
            ->where('sub_editor_id', $user->id)
            ->when($assignmentId, fn ($query) => $query->where('id', $assignmentId))
            ->exists();
    }

    private function hasReviewerAssignment($user, Article $article, ?int $assignmentId = null): bool
    {
        if (!$user) {
            return false;
        }

        return DB::table('reviewer_assignments')
            ->where('article_id', $article->id)
            ->where('reviewer_id', $user->id)
            // A reviewer may access permitted manuscript files only during an accepted
            // or completed review.
            ->whereIn('status', ['accepted', 'completed'])
            ->when($assignmentId, fn ($query) => $query->where('id', $assignmentId))
            ->exists();
    }

    private function hasProductionAssignment($user, Article $article, ?int $assignmentId = null, ?string $role = null): bool
    {
        if (!$user) {
            return false;
        }

        return DB::table('production_assignments')
            ->where('article_id', $article->id)
            ->where('user_id', $user->id)
            ->when($assignmentId, fn ($query) => $query->where('id', $assignmentId))
            ->when($role, fn ($query) => $query->where('role', $role))
            ->exists();
    }
}
