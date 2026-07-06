<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Magazine;
use App\Models\Article;
use App\Models\MagazinePage;
use App\Models\CmsPage;
use Illuminate\Support\Collection;

class GlobalSearchService
{
    /**
     * Search Magazines, Articles, and Pages (CMS & Magazine Pages) based on a query.
     *
     * @param string $query
     * @param string $type
     * @param bool $limitToFive
     * @return Collection
     */
    public function search(string $query, string $type = 'all', bool $limitToFive = false): Collection
    {
        $query = trim($query);
        if (empty($query)) {
            return collect();
        }

        $results = collect();

        // 1. Magazines (Search title, description)
        if ($type === 'all' || $type === 'magazine') {
            $magazines = Magazine::where('title', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->get();

            foreach ($magazines as $magazine) {
                $results->push([
                    'id' => $magazine->id,
                    'title' => $magazine->title,
                    'type' => 'magazine',
                    'target_url' => "/magazines/{$magazine->slug}",
                    'additional' => [
                        'description' => strip_tags($magazine->description),
                        'cover_image' => $magazine->cover_image,
                    ]
                ]);
            }
        }

        // 2. Articles (Search title, abstract, tags - accepted/legacy approved compatibility)
        if ($type === 'all' || $type === 'article') {
            $articles = Article::with(['magazine', 'tags', 'user'])
                ->whereIn('status', ArticleStatus::queryValues(ArticleStatus::ACCEPTED))
                ->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                        ->orWhere('abstract', 'like', "%{$query}%")
                        ->orWhereHas('tags', function ($tagQ) use ($query) {
                            $tagQ->where('name', 'like', "%{$query}%");
                        });
                })
                ->get();

            foreach ($articles as $article) {
                $results->push([
                    'id' => $article->id,
                    'title' => $article->title,
                    'type' => 'article',
                    'target_url' => "/articles/{$article->slug}",
                    'additional' => [
                        'abstract' => strip_tags($article->abstract),
                        'author' => $article->user ? $article->user->name : null,
                        'magazine_title' => $article->magazine ? $article->magazine->title : null,
                        'tags' => $article->tags->pluck('name')->toArray(),
                    ]
                ]);
            }
        }

        // 3. Magazine Pages & CMS Pages (Search title)
        if ($type === 'all' || $type === 'page') {
            // Magazine custom pages
            $magazinePages = MagazinePage::with('magazine')
                ->where('title', 'like', "%{$query}%")
                ->get();

            foreach ($magazinePages as $page) {
                if (!$page->magazine) {
                    continue;
                }
                $results->push([
                    'id' => $page->id,
                    'title' => $page->title,
                    'type' => 'page',
                    'target_url' => "/magazines/{$page->magazine->slug}/pages/{$page->slug}",
                    'additional' => [
                        'magazine_title' => $page->magazine->title,
                        'source' => 'magazine_page'
                    ]
                ]);
            }

            // General CMS pages (e.g. privacy, terms)
            $cmsPages = CmsPage::where('is_active', true)
                ->where('title', 'like', "%{$query}%")
                ->get();

            foreach ($cmsPages as $page) {
                $results->push([
                    'id' => $page->id,
                    'title' => $page->title,
                    'type' => 'page',
                    'target_url' => "/{$page->slug}",
                    'additional' => [
                        'description' => strip_tags($page->content_text),
                        'source' => 'cms_page'
                    ]
                ]);
            }
        }

        if ($limitToFive) {
            return $results->take(5);
        }

        return $results;
    }
}
