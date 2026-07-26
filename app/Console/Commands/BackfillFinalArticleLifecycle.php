<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\LifecycleStatusProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillFinalArticleLifecycle extends Command
{
    protected $signature = 'articles:backfill-final-lifecycle {--dry-run} {--article=} {--publication=} {--chunk=100}';

    protected $description = 'Restart-safe diagnostic backfill for final article lifecycle version scope and projections.';

    public function handle(LifecycleStatusProjector $projector): int
    {
        $counts = ['mapped_explicitly' => 0, 'mapped_confidently' => 0, 'ambiguous' => 0, 'unmapped' => 0, 'skipped' => 0, 'failed' => 0];
        $query = Article::query()->with(['versions', 'activeAcceptedFileSet']);
        if ($id = $this->option('article')) {
            $query->whereKey($id);
        }
        if ($publication = $this->option('publication')) {
            $query->where('magazine_id', $publication);
        }
        $query->orderBy('id')->chunkById(max(10, (int) $this->option('chunk')), function ($articles) use (&$counts, $projector) {
            foreach ($articles as $article) {
                try {
                    if ($article->versions->isEmpty()) {
                        $counts['ambiguous']++;
                        $this->warn("Article {$article->id}: no deterministic immutable version; administrative review required.");

                        continue;
                    }
                    $hasAmbiguousVersionScope = $article->versions->count() > 1
                        && collect(['subEditorAssignments', 'reviewerAssignments', 'editorialDecisions'])
                            ->contains(fn ($relation) => $article->{$relation}()->whereNull('article_version_id')->exists());
                    if ($hasAmbiguousVersionScope) {
                        $counts['ambiguous']++;
                        $this->warn("Article {$article->id}: historical assignments or decisions cannot be mapped safely across multiple versions.");

                        continue;
                    }
                    $latest = $article->versions->sortByDesc('version_number')->first();
                    $accepted = $article->versions->whereNotNull('accepted_at')->sortByDesc('accepted_at')->first();
                    if (! $this->option('dry-run')) {
                        DB::transaction(function () use ($article, $latest, $accepted, $projector) {
                            $article->update(['current_version_id' => $latest->id, 'accepted_version_id' => $accepted?->id]);
                            foreach (['subEditorAssignments', 'reviewerAssignments', 'editorialDecisions'] as $relation) {
                                $article->{$relation}()->whereNull('article_version_id')->update(['article_version_id' => $latest->id]);
                            }
                            if ($set = $article->activeAcceptedFileSet) {
                                $article->productionAssignments()->whereNull('article_version_id')->update(['article_version_id' => $set->article_version_id, 'accepted_file_set_id' => $set->id]);
                            }
                            $projector->synchronize($article->fresh());
                        });
                    }
                    $counts[$article->lifecycle_status ? 'mapped_explicitly' : 'mapped_confidently']++;
                } catch (\Throwable $exception) {
                    $counts['failed']++;
                    $this->error("Article {$article->id}: {$exception->getMessage()}");
                }
            }
        });
        $this->table(['Result', 'Count'], collect($counts)->map(fn ($value, $key) => [$key, $value])->values()->all());
        $this->info($this->option('dry-run') ? 'Dry run complete; no records changed.' : 'Backfill complete.');

        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
