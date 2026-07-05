<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\MediaUploadSession;
use App\Services\Media\DirectS3UploadService;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ArticleFileController extends Controller
{
    public function store(Request $request, int $articleId): JsonResponse
    {
        $article = Article::findOrFail($articleId);
        $user = $request->user();

        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,doc,docx,xlsx,xls,csv,zip,png,jpg,jpeg,txt|max:25600',
            'file_type' => ['required', Rule::in(ArticleFile::TYPES)],
            'assignment_type' => 'nullable|string|max:80',
            'assignment_id' => 'nullable|integer|min:1',
        ]);

        if (
            in_array($validated['file_type'], [ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY], true)
            && !ArticleStatus::isEditableStatus($article->status)
        ) {
            return response()->json(['message' => 'This manuscript cannot be edited at its current workflow stage.'], 422);
        }

        if (!$this->canUpload($user, $article, $validated['file_type'], $validated['assignment_type'] ?? null, $validated['assignment_id'] ?? null)) {
            return response()->json(['message' => 'Forbidden. You cannot upload this file type for this article.'], 403);
        }

        $file = $this->storeUploadedFile($article, $request->file('file'), $validated['file_type'], $user->id, [
            'assignment_type' => $validated['assignment_type'] ?? null,
            'assignment_id' => $validated['assignment_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'Article file uploaded.',
            'file' => $this->serializeFile($file),
        ], 201);
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

            return response()->json([
                'url' => app(DirectS3UploadService::class)->temporaryDownloadUrl($file->disk, $key, $file->safe_original_name ?: $file->original_name),
                'expires_in_seconds' => config('media_uploads.download_url_ttl_minutes') * 60,
            ]);
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

    public function storeUploadedFile(Article $article, \Illuminate\Http\UploadedFile $uploadedFile, string $fileType, int $uploadedBy, array $extra = []): ArticleFile
    {
        $path = app(MediaStorageService::class)->storeUploadedFile($uploadedFile, "article-files/{$article->id}/{$fileType}");

        return ArticleFile::create([
            'article_id' => $article->id,
            'article_version_id' => $extra['article_version_id'] ?? null,
            'source_asset_id' => $extra['source_asset_id'] ?? null,
            'uploaded_by' => $uploadedBy,
            'assignment_type' => $extra['assignment_type'] ?? null,
            'assignment_id' => $extra['assignment_id'] ?? null,
            'file_type' => $fileType,
            'visibility' => $this->defaultVisibility($fileType),
            'file_path' => $path,
            'storage_key' => $path,
            'original_name' => basename($uploadedFile->getClientOriginalName()),
            'safe_original_name' => basename($uploadedFile->getClientOriginalName()),
            'mime_type' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
            'disk' => config('media_uploads.disk'),
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'metadata' => $extra['metadata'] ?? null,
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

        if ($this->isGlobal($user)) {
            return true;
        }

        if (!$user) {
            return in_array($file->file_type, [ArticleFile::SUPPLEMENTARY, ArticleFile::PUBLICATION_PDF], true)
                && ArticleStatus::normalize($article->status) === ArticleStatus::PUBLISHED;
        }

        if ($article->user_id === $user->id || $this->isAuthorRecord($user, $article)) {
            return in_array($file->file_type, [
                ArticleFile::MANUSCRIPT,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::COPY_EDITED_FILE,
                ArticleFile::PROOF_FILE,
                ArticleFile::PUBLICATION_PDF,
            ], true);
        }

        if ($this->isAssignedToMagazine($user, $article->magazine_id, ['editor'])) {
            return true;
        }

        if ($this->isAssignedToMagazine($user, $article->magazine_id, ['publisher'])) {
            return in_array($file->file_type, [
                ArticleFile::COPY_EDITED_FILE,
                ArticleFile::PROOF_FILE,
                ArticleFile::PUBLICATION_PDF,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::MANUSCRIPT,
            ], true);
        }

        if ($this->hasSubEditorAssignment($user, $article)) {
            return in_array($file->file_type, [
                ArticleFile::MANUSCRIPT,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::PLAGIARISM_REPORT,
                ArticleFile::ANNOTATED_MANUSCRIPT,
                ArticleFile::REVIEWED_MANUSCRIPT,
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
            return in_array($file->file_type, [
                ArticleFile::MANUSCRIPT,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::COPY_EDITED_FILE,
                ArticleFile::PROOF_FILE,
                ArticleFile::PUBLICATION_PDF,
            ], true);
        }

        if (
            $this->hasProductionAssignment($user, $article, null, 'proofreader')
            && $this->isAssignedToMagazine($user, $article->magazine_id, ['proofreader'])
        ) {
            return in_array($file->file_type, [
                ArticleFile::MANUSCRIPT,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::COPY_EDITED_FILE,
                ArticleFile::PROOF_FILE,
                ArticleFile::PUBLICATION_PDF,
            ], true);
        }

        return false;
    }

    public function canUploadForDirectSession($user, Article $article, string $fileType, ?string $assignmentType, ?int $assignmentId): bool
    {
        return $this->canUpload($user, $article, $fileType, $assignmentType, $assignmentId);
    }

    private function canUpload($user, Article $article, string $fileType, ?string $assignmentType, ?int $assignmentId): bool
    {
        if (
            in_array($fileType, [ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY], true)
            && !ArticleStatus::isEditableStatus($article->status)
        ) {
            return false;
        }

        if ($this->isGlobal($user)) {
            return true;
        }

        return match ($fileType) {
            ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY => $user && $user->can('update', $article),
            ArticleFile::PLAGIARISM_REPORT => $this->isAssignedToMagazine($user, $article->magazine_id, ['editor']),
            ArticleFile::ANNOTATED_MANUSCRIPT => $this->hasSubEditorAssignment($user, $article, $assignmentId),
            ArticleFile::REVIEWED_MANUSCRIPT => $this->hasReviewerAssignment($user, $article, $assignmentId),
            ArticleFile::COPY_EDITED_FILE => $this->hasProductionAssignment($user, $article, $assignmentId, 'copy_editor'),
            ArticleFile::PROOF_FILE => $this->hasProductionAssignment($user, $article, $assignmentId, 'proofreader')
                && $this->isAssignedToMagazine($user, $article->magazine_id, ['proofreader']),
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
