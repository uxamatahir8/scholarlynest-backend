<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Magazine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlugNormalizationService
{
    public function __construct(private readonly SlugService $slugs) {}

    public function audit(): array
    {
        $publications = Magazine::query()->orderBy('id')->get();
        $articles = Article::query()->orderBy('magazine_id')->orderBy('id')->get();
        $publicationPlans = $this->plans($publications, 'publication');
        $articlePlans = $articles->groupBy('magazine_id')->flatMap(fn (Collection $group) => $this->plans($group, 'article'))->values();
        $plans = $publicationPlans->concat($articlePlans)->values();
        $manualReviews = $publications->filter(fn ($record) => $this->isAmbiguous($record, 'publication'))
            ->map(fn ($record) => $this->manualPlan($record, 'publication'))
            ->concat($articles->filter(fn ($record) => $this->isAmbiguous($record, 'article'))->map(fn ($record) => $this->manualPlan($record, 'article')))
            ->values();

        return [
            'plans' => $plans->all(),
            'manual_reviews' => $manualReviews->all(),
            'summary' => [
                'magazines_inspected' => $publications->where('publication_type', Magazine::TYPE_MAGAZINE)->count(),
                'journals_inspected' => $publications->where('publication_type', Magazine::TYPE_JOURNAL)->count(),
                'articles_inspected' => $articles->count(),
                'legacy_random_slugs_found' => $plans->count(),
                'safe_normalizations' => $plans->where('action', 'SAFE')->count(),
                'numeric_collision_resolutions' => $plans->where('collision', true)->count(),
                'manual_review_records' => $manualReviews->count(),
                'redirects_to_create' => $plans->count(),
            ],
        ];
    }

    public function apply(array $audit): array
    {
        $applied = [];
        $skipped = [];
        foreach ($audit['plans'] as $plan) {
            try {
                $changed = DB::transaction(function () use ($plan) {
                    $model = $plan['entity_type'] === 'publication'
                        ? Magazine::query()->lockForUpdate()->find($plan['id'])
                        : Article::query()->lockForUpdate()->find($plan['id']);
                    if (!$model || $model->slug !== $plan['current_slug'] || !$this->isProvenLegacy($model, $plan['entity_type'])) return false;

                    $conflict = $plan['entity_type'] === 'publication'
                        ? Magazine::where('slug', $plan['proposed_slug'])->whereKeyNot($model->id)->exists()
                        : Article::where('magazine_id', $model->magazine_id)->where('slug', $plan['proposed_slug'])->whereKeyNot($model->id)->exists();
                    if ($conflict) return false;

                    $oldSlug = $model->slug;
                    $model->update(['slug' => $plan['proposed_slug']]);
                    $this->slugs->recordRedirect(
                        $plan['entity_type'], $model->id, $oldSlug, $model->slug,
                        $plan['entity_type'] === 'article' ? $model->magazine_id : null
                    );
                    Log::info('slug.normalized', ['entity_type' => $plan['entity_type'], 'entity_id' => $model->id]);
                    return true;
                });
                if ($changed) $applied[] = $plan;
                else $skipped[] = $plan;
            } catch (\Throwable $exception) {
                Log::warning('slug.normalization_skipped', ['entity_type' => $plan['entity_type'], 'entity_id' => $plan['id']]);
                $skipped[] = $plan;
            }
        }
        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function plans(Collection $records, string $type): Collection
    {
        $legacy = $records->filter(fn ($record) => $this->isProvenLegacy($record, $type));
        $occupied = $records->reject(fn ($record) => $legacy->contains('id', $record->id))->pluck('slug')->flip();

        return $legacy->sortBy('id')->map(function ($record) use ($type, &$occupied) {
            $base = $this->legacyBase($record, $type);
            $attempt = 1;
            while ($occupied->has($this->slugs->candidate($base, $attempt))) $attempt++;
            $proposed = $this->slugs->candidate($base, $attempt);
            $occupied->put($proposed, true);
            return [
                'entity_type' => $type,
                'publication_type' => $type === 'publication' ? $record->publication_type : $record->magazine?->publication_type,
                'id' => $record->id,
                'parent_id' => $type === 'article' ? $record->magazine_id : null,
                'title' => $record->title,
                'current_slug' => $record->slug,
                'base_slug' => $base,
                'proposed_slug' => $proposed,
                'collision' => $attempt > 1,
                'redirect_required' => true,
                'action' => 'SAFE',
            ];
        })->values();
    }

    private function isProvenLegacy(object $record, string $type): bool
    {
        return $this->legacyBase($record, $type) !== null;
    }

    private function legacyBase(object $record, string $type): ?string
    {
        $titleBase = $this->slugs->base($record->title, $type === 'publication' ? 'publication' : 'draft');
        if ($record->slug === $titleBase) return null;
        $pattern = $type === 'publication' ? '[A-Za-z0-9]{5}' : '[a-z0-9]{6}';
        if (preg_match('/^(.+)-('.$pattern.')$/', $record->slug, $matches) !== 1) return null;
        return $matches[1] === $titleBase ? $titleBase : $matches[1];
    }

    private function isAmbiguous(object $record, string $type): bool
    {
        $base = $this->slugs->base($record->title, $type === 'publication' ? 'publication' : 'draft');
        if (!str_starts_with($record->slug, $base.'-') || $this->isProvenLegacy($record, $type)) return false;
        $tail = substr($record->slug, strlen($base) + 1);
        return strlen($tail) === ($type === 'publication' ? 5 : 6);
    }

    private function manualPlan(object $record, string $type): array
    {
        return [
            'entity_type' => $type, 'id' => $record->id, 'current_slug' => $record->slug,
            'base_slug' => $this->slugs->base($record->title), 'action' => 'MANUAL REVIEW',
        ];
    }
}
