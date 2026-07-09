<?php

namespace App\Services;

use App\Models\Article;

class CitationService
{
    public function apa(Article $article): string
    {
        $article->loadMissing(['articleAuthors', 'magazine', 'issue']);

        $authors = $article->articleAuthors
            ->sortBy('author_order')
            ->map(fn ($author) => $this->formatAuthor($author->co_author_name))
            ->filter()
            ->values();

        $authorText = $authors->isEmpty()
            ? ($article->user?->name ?? 'Unknown Author')
            : $this->joinAuthors($authors->all());

        $year = $article->published_year ?: optional($article->published_at)->year ?: now()->year;
        $magazine = $article->magazine?->title ?? 'ScholarlyNest';
        $volume = $article->issue?->volume_number;
        $issue = $article->issue?->issue_number;
        $pages = $article->page_start && $article->page_end
            ? "{$article->page_start}-{$article->page_end}"
            : null;

        $citation = "{$authorText} ({$year}). {$article->title}. {$magazine}";

        if ($volume) {
            $citation .= ", {$volume}";
            if ($issue) {
                $citation .= "({$issue})";
            }
        }

        if ($pages) {
            $citation .= ", {$pages}";
        }

        $citation .= '.';

        if ($article->doi) {
            $citation .= ' https://doi.org/' . ltrim($article->doi, '/');
        }

        return $citation;
    }

    private function formatAuthor(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name);
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);
        $initials = collect($parts)
            ->map(fn ($part) => mb_substr($part, 0, 1) . '.')
            ->implode(' ');

        return "{$last}, {$initials}";
    }

    private function joinAuthors(array $authors): string
    {
        if (count($authors) === 1) {
            return $authors[0];
        }

        if (count($authors) === 2) {
            return $authors[0] . ' & ' . $authors[1];
        }

        $last = array_pop($authors);

        return implode(', ', $authors) . ', & ' . $last;
    }
}
