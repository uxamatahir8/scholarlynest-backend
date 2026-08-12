<?php

namespace App\Services;

use App\Http\Controllers\ArticleFileController;
use App\Models\Article;
use App\Models\ArticleFile;

class PublishedArticleFileResolver
{
    public const SECTION_PUBLISHED_PDF = 'published_pdf';

    public const SECTION_PUBLIC_IMAGES = 'public_images';

    public const SECTION_FIGURES = 'figures';

    public const SECTION_TABLES = 'tables';

    public const SECTION_SUPPLEMENTARY_DOWNLOADS = 'supplementary_downloads';

    public const SECTION_RESEARCH_DATA = 'research_data';

    public const SECTION_GRAPHICAL_ABSTRACT = 'graphical_abstract';

    public const SECTION_COVER_IMAGE = 'cover_image';

    public const SECTION_SUPPORTING_DOCUMENTS = 'supporting_documents';

    public const SECTION_OTHER_PUBLIC_FILES = 'other_public_files';

    public const SECTIONS_CONFIG = [
        self::SECTION_PUBLISHED_PDF => ['title' => 'Published Article PDF', 'order' => 1],
        self::SECTION_PUBLIC_IMAGES => ['title' => 'Public Article Images', 'order' => 2],
        self::SECTION_FIGURES => ['title' => 'Figures', 'order' => 3],
        self::SECTION_TABLES => ['title' => 'Tables', 'order' => 4],
        self::SECTION_SUPPLEMENTARY_DOWNLOADS => ['title' => 'Supplementary Downloads', 'order' => 5],
        self::SECTION_RESEARCH_DATA => ['title' => 'Research Data', 'order' => 6],
        self::SECTION_GRAPHICAL_ABSTRACT => ['title' => 'Graphical Abstract', 'order' => 7],
        self::SECTION_COVER_IMAGE => ['title' => 'Cover Image', 'order' => 8],
        self::SECTION_SUPPORTING_DOCUMENTS => ['title' => 'Supporting Documents', 'order' => 9],
        self::SECTION_OTHER_PUBLIC_FILES => ['title' => 'Other Public Files', 'order' => 10],
    ];

    public function resolveSectionKey(ArticleFile $file, Article $article): string
    {
        if ($article->isDirectPublication()) {
            return match ($file->file_type) {
                ArticleFile::DIRECT_PUBLICATION_MANUSCRIPT => self::SECTION_PUBLISHED_PDF,
                ArticleFile::DIRECT_PUBLICATION_FIGURE => self::SECTION_FIGURES,
                ArticleFile::DIRECT_PUBLICATION_SUPPLEMENTARY => self::SECTION_SUPPLEMENTARY_DOWNLOADS,
                ArticleFile::DIRECT_PUBLICATION_COVER => self::SECTION_COVER_IMAGE,
                default => self::SECTION_OTHER_PUBLIC_FILES,
            };
        }

        $sectionKey = data_get($file->metadata, 'section_key')
            ?? data_get($file->metadata, 'document_type')
            ?? data_get($file->metadata, 'purpose');

        if ($sectionKey && array_key_exists($sectionKey, self::SECTIONS_CONFIG)) {
            return $sectionKey;
        }

        if ($file->file_type === ArticleFile::PUBLICATION_PDF) {
            return self::SECTION_PUBLISHED_PDF;
        }

        $purposeLower = strtolower($sectionKey ?? $file->file_type);

        if (str_contains($purposeLower, 'graphical_abstract') || str_contains($purposeLower, 'graphicalabstract')) {
            return self::SECTION_GRAPHICAL_ABSTRACT;
        }
        if (str_contains($purposeLower, 'cover_image') || str_contains($purposeLower, 'coverimage')) {
            return self::SECTION_COVER_IMAGE;
        }
        if (str_contains($purposeLower, 'supporting_document') || str_contains($purposeLower, 'supporting')) {
            return self::SECTION_SUPPORTING_DOCUMENTS;
        }

        $isImage = str_starts_with($file->mime_type ?? '', 'image/')
            || in_array(strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp']);

        if ($isImage) {
            return self::SECTION_PUBLIC_IMAGES;
        }

        if (str_contains($purposeLower, 'figure')) {
            return self::SECTION_FIGURES;
        }
        if (str_contains($purposeLower, 'table')) {
            return self::SECTION_TABLES;
        }
        if (str_contains($purposeLower, 'research_data') || str_contains($purposeLower, 'data')) {
            return self::SECTION_RESEARCH_DATA;
        }
        if ($file->file_type === ArticleFile::SUPPLEMENTARY) {
            return self::SECTION_SUPPLEMENTARY_DOWNLOADS;
        }

        return self::SECTION_OTHER_PUBLIC_FILES;
    }

    public function resolvePublishedFiles(Article $article): array
    {
        if ($article->isDirectPublication()) {
            if ($article->status !== 'published') {
                return [];
            }
            $publication = $article->publicationRecords()->where('publication_mode', 'direct')->where('status', 'published')->latest('published_at')->first();
            if (! $publication) {
                return [];
            }

            return ArticleFile::query()->whereHas('publicationSelections', fn ($query) => $query
                ->where('publication_record_id', $publication->id)->where('is_public', true))
                ->where('scan_status', 'clean')->orderBy('id')->get()
                ->filter(fn (ArticleFile $file) => data_get($file->metadata, 'direct_publication.active', true) !== false)
                ->values()->all();
        }
        $article->loadMissing('files');

        return $article->files
            ->filter(fn ($file) => ($file->scan_status ?? 'clean') === 'clean')
            ->filter(function ($file) use ($article) {
                $isPublicationVisible = (bool) data_get($file->metadata, 'publication_visibility.show_on_article')
                    || (bool) data_get($file->metadata, 'publication_visibility.show_in_downloads');
                $isActivePublicationPdf = $file->file_type === ArticleFile::PUBLICATION_PDF
                    && ($file->storage_key ?: $file->file_path) === $article->pdf_path;

                return $isPublicationVisible || $isActivePublicationPdf || $file->file_type === ArticleFile::SUPPLEMENTARY;
            })
            ->values()
            ->all();
    }

    public function resolvePublishedFilesPayload(Article $article): array
    {
        $files = $this->resolvePublishedFiles($article);
        $fileController = app(ArticleFileController::class);

        $serializedFiles = collect($files)->map(fn ($file) => $fileController->serializeFile($file))->values()->all();

        $grouped = [];
        foreach ($serializedFiles as $sFile) {
            $origFile = collect($files)->first(fn ($f) => $f->id === $sFile['id']);
            $sectionKey = $origFile ? $this->resolveSectionKey($origFile, $article) : self::SECTION_OTHER_PUBLIC_FILES;
            $grouped[$sectionKey][] = $sFile;
        }

        $sections = [];
        foreach (self::SECTIONS_CONFIG as $key => $config) {
            $filesInSection = $grouped[$key] ?? [];

            // Sort files: created_at, then id
            usort($filesInSection, function ($a, $b) {
                $timeA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
                $timeB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
                if ($timeA === $timeB) {
                    return $a['id'] <=> $b['id'];
                }

                return $timeA <=> $timeB;
            });

            $sections[] = [
                'key' => $key,
                'title' => $config['title'],
                'order' => $config['order'],
                'files' => $filesInSection,
            ];
        }

        return $sections;
    }
}
