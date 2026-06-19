<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleFile;
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
        $file = ArticleFile::with(['article', 'uploader:id,name,email'])->findOrFail($fileId);

        if (!$this->canAccess($request->user('sanctum'), $file)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $relativePath = str_replace('storage/', '', $file->file_path);
        if (!Storage::disk('public')->exists($relativePath)) {
            return response()->json(['message' => 'The file could not be found on storage.'], 404);
        }

        return response()->file(Storage::disk('public')->path($relativePath), [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'attachment; filename="' . $file->original_name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeUploadedFile(Article $article, \Illuminate\Http\UploadedFile $uploadedFile, string $fileType, int $uploadedBy, array $extra = []): ArticleFile
    {
        $path = $uploadedFile->store("article-files/{$article->id}/{$fileType}", 'public');

        return ArticleFile::create([
            'article_id' => $article->id,
            'article_version_id' => $extra['article_version_id'] ?? null,
            'source_asset_id' => $extra['source_asset_id'] ?? null,
            'uploaded_by' => $uploadedBy,
            'assignment_type' => $extra['assignment_type'] ?? null,
            'assignment_id' => $extra['assignment_id'] ?? null,
            'file_type' => $fileType,
            'visibility' => $this->defaultVisibility($fileType),
            'file_path' => 'storage/' . $path,
            'original_name' => basename($uploadedFile->getClientOriginalName()),
            'mime_type' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
            'metadata' => $extra['metadata'] ?? null,
        ]);
    }

    public function serializeFile(ArticleFile $file): array
    {
        $file->loadMissing('uploader:id,name,email');

        return [
            'id' => $file->id,
            'article_id' => $file->article_id,
            'file_type' => $file->file_type,
            'visibility' => $file->visibility,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'uploaded_by' => $file->uploaded_by,
            'uploader' => $file->uploader,
            'created_at' => $file->created_at,
            'download_url' => "/api/articles/files/{$file->id}/download",
            'assignment_type' => $file->assignment_type,
            'assignment_id' => $file->assignment_id,
            'metadata' => $file->metadata,
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
                && in_array($article->status, ['accepted', 'published'], true);
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

        if ($this->hasProductionAssignment($user, $article)) {
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

    private function canUpload($user, Article $article, string $fileType, ?string $assignmentType, ?int $assignmentId): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        return match ($fileType) {
            ArticleFile::MANUSCRIPT, ArticleFile::SUPPLEMENTARY => $user && $user->can('update', $article),
            ArticleFile::PLAGIARISM_REPORT => $this->isAssignedToMagazine($user, $article->magazine_id, ['editor']),
            ArticleFile::ANNOTATED_MANUSCRIPT => $this->hasSubEditorAssignment($user, $article, $assignmentId),
            ArticleFile::REVIEWED_MANUSCRIPT => $this->hasReviewerAssignment($user, $article, $assignmentId),
            ArticleFile::COPY_EDITED_FILE => $this->hasProductionAssignment($user, $article, $assignmentId, 'copy_editor'),
            ArticleFile::PROOF_FILE => $this->hasProductionAssignment($user, $article, $assignmentId, 'proofreader'),
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
