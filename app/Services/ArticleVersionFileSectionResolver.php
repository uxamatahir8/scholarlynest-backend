<?php

namespace App\Services;

use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\User;

class ArticleVersionFileSectionResolver
{
    public const SECTION_PRIMARY_MANUSCRIPT = 'primary_manuscript';
    public const SECTION_RESPONSE_TO_REVIEWERS = 'response_to_reviewers';
    public const SECTION_COVER_LETTER = 'cover_letter';
    public const SECTION_FIGURES = 'figures';
    public const SECTION_TABLES = 'tables';
    public const SECTION_SUPPLEMENTARY_MATERIALS = 'supplementary_materials';
    public const SECTION_RESEARCH_DATA = 'research_data';
    public const SECTION_ETHICS_DOCUMENTS = 'ethics_documents';
    public const SECTION_DECLARATIONS = 'declarations';
    public const SECTION_ADDITIONAL_SUBMISSION_FILES = 'additional_submission_files';

    public const SECTIONS_CONFIG = [
        self::SECTION_PRIMARY_MANUSCRIPT => ['title' => 'Primary Manuscript', 'order' => 1],
        self::SECTION_RESPONSE_TO_REVIEWERS => ['title' => 'Response to Reviewers', 'order' => 2],
        self::SECTION_COVER_LETTER => ['title' => 'Cover Letter', 'order' => 3],
        self::SECTION_FIGURES => ['title' => 'Figures and Images', 'order' => 4],
        self::SECTION_TABLES => ['title' => 'Tables', 'order' => 5],
        self::SECTION_SUPPLEMENTARY_MATERIALS => ['title' => 'Supplementary Materials', 'order' => 6],
        self::SECTION_RESEARCH_DATA => ['title' => 'Research Data', 'order' => 7],
        self::SECTION_ETHICS_DOCUMENTS => ['title' => 'Ethics Documents', 'order' => 8],
        self::SECTION_DECLARATIONS => ['title' => 'Declarations', 'order' => 9],
        self::SECTION_ADDITIONAL_SUBMISSION_FILES => ['title' => 'Additional Submission Files', 'order' => 10],
    ];

    public function resolveSectionKey(ArticleFile $file): string
    {
        // 1. Check explicit persisted metadata
        $sectionKey = data_get($file->metadata, 'section_key')
            ?? data_get($file->metadata, 'document_type')
            ?? data_get($file->metadata, 'purpose');

        if ($sectionKey && array_key_exists($sectionKey, self::SECTIONS_CONFIG)) {
            return $sectionKey;
        }

        // Map purpose/category checks
        $purpose = data_get($file->metadata, 'purpose') ?? $file->file_type;
        $purposeLower = strtolower($purpose);

        if (str_contains($purposeLower, 'manuscript') && !str_contains($purposeLower, 'additional')) {
            return self::SECTION_PRIMARY_MANUSCRIPT;
        }
        if (str_contains($purposeLower, 'revision_response') || str_contains($purposeLower, 'response_to_reviewers')) {
            return self::SECTION_RESPONSE_TO_REVIEWERS;
        }
        if (str_contains($purposeLower, 'cover_letter') || str_contains($purposeLower, 'coverletter')) {
            return self::SECTION_COVER_LETTER;
        }
        if (str_contains($purposeLower, 'figure') || str_contains($purposeLower, 'image')) {
            return self::SECTION_FIGURES;
        }
        if (str_contains($purposeLower, 'table')) {
            return self::SECTION_TABLES;
        }
        if (str_contains($purposeLower, 'supplementary')) {
            return self::SECTION_SUPPLEMENTARY_MATERIALS;
        }
        if (str_contains($purposeLower, 'research_data') || str_contains($purposeLower, 'data')) {
            return self::SECTION_RESEARCH_DATA;
        }
        if (str_contains($purposeLower, 'ethics')) {
            return self::SECTION_ETHICS_DOCUMENTS;
        }
        if (str_contains($purposeLower, 'declaration')) {
            return self::SECTION_DECLARATIONS;
        }

        // Fallbacks based on file_type
        switch ($file->file_type) {
            case ArticleFile::MANUSCRIPT:
                return self::SECTION_PRIMARY_MANUSCRIPT;
            case ArticleFile::REVISION_RESPONSE:
                return self::SECTION_RESPONSE_TO_REVIEWERS;
            case ArticleFile::SUPPLEMENTARY:
                return self::SECTION_SUPPLEMENTARY_MATERIALS;
            case ArticleFile::ADDITIONAL_MANUSCRIPT_FILE:
                return self::SECTION_ADDITIONAL_SUBMISSION_FILES;
        }

        return self::SECTION_ADDITIONAL_SUBMISSION_FILES;
    }

    public function groupFilesIntoSections(array $serializedFiles, $originalFiles): array
    {
        // Map original files by ID
        $originalMap = collect($originalFiles)->keyBy('id');

        $grouped = [];
        foreach ($serializedFiles as $sFile) {
            $fileId = $sFile['id'];
            $origFile = $originalMap->get($fileId);
            $sectionKey = $origFile ? $this->resolveSectionKey($origFile) : self::SECTION_ADDITIONAL_SUBMISSION_FILES;
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
