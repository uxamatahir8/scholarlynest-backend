<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\MediaUploadSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArticleFileController extends Controller
{
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
            if (!$key || !Storage::disk($file->disk)->exists($key)) {
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

        return ArticleFile::create([
            'article_id' => $article->id,
            'article_version_id' => $metadata['article_version_id'] ?? $article->versions()->latest('version_number')->value('id'),
            'uploaded_by' => $upload->user_id,
            'assignment_type' => $metadata['assignment_type'] ?? null,
            'assignment_id' => $metadata['assignment_id'] ?? null,
            'file_type' => $purposeConfig['article_file_type'],
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
        return ArticleFile::create([
            'article_id' => $article->id,
            'article_version_id' => $extra['article_version_id'] ?? $article->versions()->latest('version_number')->value('id'),
            'uploaded_by' => $upload->user_id,
            'assignment_type' => $extra['assignment_type'] ?? ($upload->metadata['assignment_type'] ?? null),
            'assignment_id' => $extra['assignment_id'] ?? ($upload->metadata['assignment_id'] ?? null),
            'file_type' => $purposeConfig['article_file_type'],
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
        ]);
    }

    public function serializeFile(ArticleFile $file): array
    {
        $file->loadMissing('uploader:id,name');

        return [
            'id' => $file->id,
            'article_id' => $file->article_id,
            'article_version_id' => $file->article_version_id,
            'file_type' => $file->file_type,
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
        ];
    }

    public function filterVisibleFiles($user, iterable $files): array
    {
        return collect($files)
            ->filter(fn (ArticleFile $file) => $this->canAccess($user, $file))
            ->map(fn (ArticleFile $file) => $this->serializeFile($file))
            ->values()
            ->all();
    }

    public function canAccess($user, ArticleFile $file): bool
    {
        $article = $file->article;

        if (!$article) {
            return false;
        }

        // Article file viewing is available to every authenticated account,
        // regardless of role or workflow assignment. Upload authorization and
        // clean-scan availability remain enforced separately.
        if ($user) {
            return true;
        }

        return in_array($file->file_type, [ArticleFile::SUPPLEMENTARY, ArticleFile::PUBLICATION_PDF], true)
            && ArticleStatus::normalize($article->status) === ArticleStatus::PUBLISHED;
    }

    public function canUploadForDirectSession($user, ?Article $article, string $fileType, ?string $assignmentType, ?int $assignmentId): bool
    {
        return $this->canUpload($user, $article, $fileType, $assignmentType, $assignmentId);
    }

    private function canUpload($user, ?Article $article, string $fileType, ?string $assignmentType, ?int $assignmentId): bool
    {
        if (
            $article && in_array($fileType, [ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY], true)
            && !ArticleStatus::isEditableStatus($article->status)
        ) {
            return false;
        }

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
            return false;
        }

        return match ($fileType) {
            ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY => $user && $user->can('update', $article),
            ArticleFile::PLAGIARISM_REPORT => $this->isAssignedToMagazine($user, $article->magazine_id, ['editor']),
            ArticleFile::ANNOTATED_MANUSCRIPT => $this->hasSubEditorAssignment($user, $article, $assignmentId),
            ArticleFile::REVIEWED_MANUSCRIPT => $this->hasReviewerAssignment($user, $article, $assignmentId),
            ArticleFile::COPY_EDITED_FILE => $this->hasProductionAssignment($user, $article, $assignmentId, 'copy_editor'),
            ArticleFile::PROOF_FILE => false,
            ArticleFile::PUBLICATION_PDF => $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher']),
            default => false,
        };
    }

    private function defaultVisibility(string $fileType): string
    {
        return match ($fileType) {
            ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY, ArticleFile::PROOF_FILE, ArticleFile::PUBLICATION_PDF => 'author_visible',
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
            // A reviewer may access permitted manuscript files only during an accepted,
            // active review. Pending invitations and completed reviews never grant access.
            ->where('status', 'accepted')
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
