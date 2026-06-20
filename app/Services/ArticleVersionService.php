<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArticleVersionService
{
    public function createSnapshot(
        Article $article,
        User $user,
        string $label,
        ?string $changeSummary = null,
        ?string $authorResponse = null,
        array $linkFileIds = []
    ): ArticleVersion {
        return DB::transaction(function () use ($article, $user, $label, $changeSummary, $authorResponse, $linkFileIds) {
            $article->loadMissing(['articleAuthors', 'tags', 'files.uploader:id,name,email']);

            $version = ArticleVersion::create([
                'article_id' => $article->id,
                'created_by' => $user->id,
                'version_number' => $this->nextVersionNumber($article),
                'label' => $label,
                'status_snapshot' => $article->status,
                'metadata_snapshot' => $this->metadataSnapshot($article),
                'file_snapshot' => [],
                'change_summary' => $changeSummary,
                'author_response' => $authorResponse,
            ]);

            $this->linkFiles($article, $version, $linkFileIds);

            $version->update([
                'file_snapshot' => $this->fileSnapshot($article, $version),
            ]);

            return $version->fresh(['creator:id,name,email', 'files.uploader:id,name,email']);
        });
    }

    public function nextVersionNumber(Article $article): int
    {
        return ((int) $article->versions()->max('version_number')) + 1;
    }

    private function linkFiles(Article $article, ArticleVersion $version, array $linkFileIds): void
    {
        $query = ArticleFile::query()
            ->where('article_id', $article->id)
            ->whereIn('file_type', [
                ArticleFile::MANUSCRIPT,
                ArticleFile::SUPPLEMENTARY,
                ArticleFile::PUBLICATION_PDF,
            ]);

        if ($linkFileIds) {
            $query->whereIn('id', $linkFileIds);
        } else {
            $query->whereNull('article_version_id');
        }

        $query->update(['article_version_id' => $version->id]);
    }

    private function metadataSnapshot(Article $article): array
    {
        return [
            'title' => $article->title,
            'subtitle' => $article->subtitle,
            'abstract' => $article->abstract,
            'keywords' => $article->keywords,
            'article_category' => $article->article_category,
            'article_type' => $article->article_type,
            'subject_area' => $article->subject_area,
            'language' => $article->language,
            'ethical_approval_statement' => $article->ethical_approval_statement,
            'conflict_of_interest_statement' => $article->conflict_of_interest_statement,
            'funding_statement' => $article->funding_statement,
            'data_availability_statement' => $article->data_availability_statement,
            'author_contribution_statement' => $article->author_contribution_statement,
            'full_text' => $article->full_text,
            'has_pdf' => !empty($article->pdf_path),
            'doi' => $article->doi,
            'published_year' => $article->published_year,
            'published_month' => $article->published_month,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'authors' => $article->articleAuthors->map(fn ($author) => [
                'name' => $author->co_author_name,
                'affiliation' => $author->affiliation,
                'is_owner' => $author->is_owner,
                'is_corresponding' => $author->is_corresponding,
                'author_order' => $author->author_order,
            ])->values()->all(),
            'tags' => $article->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()->all(),
        ];
    }

    private function fileSnapshot(Article $article, ArticleVersion $version): array
    {
        return ArticleFile::query()
            ->where('article_id', $article->id)
            ->where('article_version_id', $version->id)
            ->with('uploader:id,name,email')
            ->get()
            ->map(fn (ArticleFile $file) => $this->serializeFileSnapshot($file))
            ->values()
            ->all();
    }

    public function serializeVersion(ArticleVersion $version, ?User $viewer = null): array
    {
        $version->loadMissing(['creator:id,name', 'files.uploader:id,name']);
        $fileController = app(\App\Http\Controllers\ArticleFileController::class);
        $visibleFiles = collect($version->files)
            ->filter(fn (ArticleFile $file) => $fileController->canAccess($viewer, $file))
            ->map(fn (ArticleFile $file) => $fileController->serializeFile($file))
            ->values()
            ->all();

        return [
            'id' => $version->id,
            'article_id' => $version->article_id,
            'version_number' => $version->version_number,
            'label' => $version->label,
            'status_snapshot' => $version->status_snapshot,
            'metadata_snapshot' => $this->safeMetadataSnapshot($version->metadata_snapshot ?? [], $viewer),
            'file_snapshot' => $this->visibleFileSnapshot($version->file_snapshot ?? [], $visibleFiles),
            'files' => $visibleFiles,
            'change_summary' => $version->change_summary,
            'author_response' => $version->author_response,
            'creator' => $version->creator ? [
                'id' => $version->creator->id,
                'name' => $version->creator->name,
            ] : null,
            'created_at' => $version->created_at,
        ];
    }

    private function visibleFileSnapshot(array $snapshot, array $visibleFiles): array
    {
        $visibleIds = collect($visibleFiles)->pluck('id')->all();

        return collect($snapshot)
            ->filter(fn ($file) => in_array($file['id'] ?? null, $visibleIds, true))
            ->values()
            ->all();
    }

    private function safeMetadataSnapshot(array $snapshot, ?User $viewer): array
    {
        unset($snapshot['pdf_path']);
        if (isset($snapshot['authors']) && is_array($snapshot['authors'])) {
            $snapshot['authors'] = collect($snapshot['authors'])
                ->map(function ($author) {
                    unset($author['email']);
                    return $author;
                })
                ->values()
                ->all();
        }

        return $snapshot;
    }

    private function serializeFileSnapshot(ArticleFile $file): array
    {
        return [
            'id' => $file->id,
            'file_type' => $file->file_type,
            'visibility' => $file->visibility,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'uploaded_by' => $file->uploaded_by,
            'uploader_name' => $file->uploader?->name,
            'created_at' => $file->created_at,
            'download_url' => "/api/articles/files/{$file->id}/download",
        ];
    }
}
