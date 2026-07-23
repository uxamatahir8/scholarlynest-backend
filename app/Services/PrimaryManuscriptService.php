<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAcceptedFileSetItem;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\MediaUploadSession;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PrimaryManuscriptService
{
    public const DUPLICATE_MESSAGE = 'A manuscript file is already uploaded for this submission. Remove it before uploading another.';
    public const MISSING_MESSAGE = 'Upload a manuscript file before submitting this article.';
    public const INVALID_MESSAGE = 'This submission has an invalid manuscript-file state. Please contact support.';

    public function assertDraftSlotAvailable(Article $article, ?string $sameUploadId = null): void
    {
        $files = ArticleFile::query()
            ->where('article_id', $article->id)
            ->whereNull('article_version_id')
            ->where('file_type', ArticleFile::MANUSCRIPT)
            ->whereNull('assignment_type')
            ->lockForUpdate()
            ->get();

        if ($files->isEmpty()) return;
        if ($sameUploadId && $files->every(fn (ArticleFile $file) => $file->media_upload_session_id === $sameUploadId)) return;
        $this->fail(self::DUPLICATE_MESSAGE, 409);
    }

    public function authoritativeForSubmission(Article $article, ArticleVersion $version): ArticleFile
    {
        $files = ArticleFile::query()
            ->where('article_id', $article->id)
            ->where('article_version_id', $version->id)
            ->where('file_type', ArticleFile::MANUSCRIPT)
            ->whereNull('assignment_type')
            ->lockForUpdate()
            ->get();

        if ($files->isEmpty()) $this->fail(self::MISSING_MESSAGE);
        if ($files->count() !== 1) $this->fail(self::INVALID_MESSAGE);

        $file = $files->first();
        if (!$this->isValid($file, $article, $version)) $this->fail(self::INVALID_MESSAGE);

        $version->update(['manuscript_file_id' => $file->id]);
        return $file;
    }

    public function validateAuthoritative(Article $article, ArticleVersion $version): ArticleFile
    {
        $version->refresh();
        $candidates = ArticleFile::query()
            ->where('article_id', $article->id)
            ->where('article_version_id', $version->id)
            ->where('file_type', ArticleFile::MANUSCRIPT)
            ->whereNull('assignment_type')
            ->lockForUpdate()
            ->get();
        if ($candidates->isEmpty()) $this->fail(self::MISSING_MESSAGE);
        if ($candidates->count() !== 1) $this->fail(self::INVALID_MESSAGE);
        $file = $candidates->first();
        if (!$this->isValid($file, $article, $version)) $this->fail(self::INVALID_MESSAGE);
        if (!$version->manuscript_file_id) {
            $version->update(['manuscript_file_id' => $file->id]);
        } elseif ((int) $version->manuscript_file_id !== (int) $file->id) {
            $this->fail(self::INVALID_MESSAGE);
        }
        return $file;
    }

    public function removeDraft(Article $article, ArticleFile $file): array
    {
        return DB::transaction(function () use ($article, $file) {
            $lockedArticle = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $lockedFile = ArticleFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
            if (!$lockedFile->isPrimaryManuscript() || (int) $lockedFile->article_id !== (int) $lockedArticle->id) abort(404);
            if ($lockedFile->article_version_id || !ArticleStatus::isEditableStatus($lockedArticle->status)) {
                $this->fail('This manuscript belongs to a submitted version and can no longer be removed.');
            }
            if (ArticleAcceptedFileSetItem::where('article_file_id', $lockedFile->id)->exists()) $this->fail(self::INVALID_MESSAGE);

            $upload = $lockedFile->media_upload_session_id
                ? MediaUploadSession::query()->whereKey($lockedFile->media_upload_session_id)->lockForUpdate()->first()
                : null;
            $keys = collect([$lockedFile->storage_key, $lockedFile->file_path, $upload?->s3_clean_key, $upload?->s3_incoming_key])->filter()->unique();
            $safeKeys = $keys->reject(fn (string $key) => ArticleFile::query()
                ->whereKeyNot($lockedFile->id)
                ->where(fn ($query) => $query->where('storage_key', $key)->orWhere('file_path', $key))
                ->exists()
                || MediaUploadSession::query()
                    ->when($upload, fn ($query) => $query->whereKeyNot($upload->id))
                    ->where(fn ($query) => $query->where('s3_clean_key', $key)->orWhere('s3_incoming_key', $key))
                    ->exists());

            $lockedFile->delete();
            if ($upload) {
                $metadata = $upload->metadata ?: [];
                unset($metadata['article_file_id']);
                $upload->forceFill([
                    'status' => MediaUploadSession::STATUS_ABORTED,
                    'failure_reason' => 'draft_manuscript_removed',
                    'metadata' => $metadata,
                ])->save();
            }
            $lockedArticle->update(['pdf_path' => null]);

            $storageWarning = false;
            foreach ($safeKeys as $key) {
                try {
                    Storage::disk($lockedFile->disk)->delete($key);
                } catch (\Throwable $exception) {
                    $storageWarning = true;
                    Log::warning('manuscript.removal_storage_cleanup_failed', ['article_id' => $article->id, 'article_file_id' => $file->id]);
                }
            }
            return ['storage_warning' => $storageWarning];
        });
    }

    private function isValid(ArticleFile $file, Article $article, ArticleVersion $version): bool
    {
        return $file->isPrimaryManuscript()
            && $file->scan_status === 'clean'
            && (bool) ($file->storage_key ?: $file->file_path)
            && (int) $file->article_id === (int) $article->id
            && (int) $file->article_version_id === (int) $version->id
            && ((int) $file->uploaded_by === (int) $article->user_id
                || $article->articleAuthors()->where('user_id', $file->uploaded_by)->exists()
                || $file->uploader?->hasPermission('articles.approve'));
    }

    private function fail(string $message, int $status = 422): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
