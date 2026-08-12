<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\ArticleThreadService;
use Illuminate\Console\Command;

class BackfillArticleThreads extends Command
{
    protected $signature = 'article-threads:backfill {--commit : Persist deterministic thread creation}';

    protected $description = 'Inventory legacy article communication and create only deterministic default threads';

    public function handle(ArticleThreadService $threads): int
    {
        $commit = (bool) $this->option('commit');
        $report = ['articles' => 0, 'mapped' => 0, 'skipped' => 0, 'ambiguous' => 0, 'failed' => 0];
        Article::query()->withCount('threads')->orderBy('id')->chunkById(100, function ($articles) use ($threads, $commit, &$report) {
            foreach ($articles as $article) {
                $report['articles']++;
                if (! $commit) {
                    $report[$article->threads_count ? 'skipped' : 'mapped']++;

                    continue;
                }
                try {
                    $before = $article->threads()->count();
                    $threads->ensureForCurrentLifecycle($article);
                    $report[$article->threads()->count() > $before ? 'mapped' : 'skipped']++;
                } catch (\Throwable $exception) {
                    $report['failed']++;
                    $this->warn("Article {$article->id}: {$exception->getMessage()}");
                }
            }
        });
        $legacyNotes = Article::query()->whereHas('reviewerAssignments', fn ($q) => $q->whereNotNull('confidential_comments'))
            ->orWhereHas('subEditorAssignments', fn ($q) => $q->whereNotNull('internal_comments'))->count();
        $report['ambiguous'] = $legacyNotes;
        $this->table(['mode', 'articles', 'mapped', 'skipped', 'ambiguous', 'failed'], [[
            $commit ? 'commit' : 'dry-run', ...array_values($report),
        ]]);
        if (! $commit) {
            $this->info('Dry run only. Use --commit after reviewing ambiguous historical notes; note bodies are never copied automatically.');
        }

        return $report['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
