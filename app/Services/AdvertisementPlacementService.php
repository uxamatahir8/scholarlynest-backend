<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Advertisement;
use App\Models\Article;
use App\Models\Magazine;
use Illuminate\Database\Eloquent\Builder;

class AdvertisementPlacementService
{
    public function forWebsitePage(string $pageKey): array
    {
        return $this->resolve(fn (Builder $q) => $q->where('target_area', 'website')->where('target_mode', 'single_page')->where('page_key', $pageKey));
    }

    public function forPublicationPage(Magazine $publication, string $pageKey): array
    {
        return $this->resolve(fn (Builder $q) => $q->where('target_area', 'publication')
            ->where('publication_type', $publication->publication_type)->where('publication_id', $publication->id)
            ->where(fn (Builder $m) => $m->where('target_mode', 'all_pages')->orWhere(fn (Builder $s) => $s->where('target_mode', 'specific_pages')->where('page_key', $pageKey))));
    }

    public function forArticlePage(Article $article): array
    {
        if (ArticleStatus::normalize($article->status) !== ArticleStatus::PUBLISHED) {
            return $this->emptyPlacements();
        }

        $article->loadMissing('magazine');
        return $this->resolve(fn (Builder $q) => $q->where('publication_type', $article->magazine->publication_type)
            ->where('publication_id', $article->magazine_id)
            ->where(fn (Builder $m) => $m
                ->where(fn (Builder $a) => $a->where('target_area', 'article')->where('target_mode', 'all_articles'))
                ->orWhere(fn (Builder $a) => $a->where('target_area', 'article')->where('target_mode', 'specific_articles')->where('article_id', $article->id))
                ->orWhere(fn (Builder $a) => $a->where('target_area', 'publication')->where('target_mode', 'all_pages'))));
    }

    private function resolve(callable $targetConstraint): array
    {
        $ads = Advertisement::query()->with('image')->where('status', 'active')
            ->whereHas('image', fn (Builder $q) => $q->where('scan_status', 'clean'))
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->whereHas('targets', $targetConstraint)->orderByDesc('priority')->orderByDesc('created_at')->get();

        return collect(Advertisement::PLACEMENTS)->mapWithKeys(fn ($placement) => [
            $placement => $ads->where('placement', $placement)->map->publicPayload()->values()->all(),
        ])->all();
    }

    private function emptyPlacements(): array
    {
        return collect(Advertisement::PLACEMENTS)->mapWithKeys(fn ($placement) => [$placement => []])->all();
    }
}
