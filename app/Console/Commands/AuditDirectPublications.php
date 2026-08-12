<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\WorkflowIdempotencyKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditDirectPublications extends Command
{
    protected $signature = 'direct-publications:audit
        {--details : Show every article in each likely duplicate group}
        {--publication= : Limit to a publication (magazine) ID}
        {--creator= : Limit to a creator user ID}
        {--draft-id= : Limit to a draft article ID}
        {--repair : Remove only deterministic, untouched duplicate drafts}';

    protected $description = 'Dry-run audit for direct-publication drafts created by the same client payload';

    public function handle(): int
    {
        $operations = WorkflowIdempotencyKey::query()
            ->where('command', 'direct.create')
            ->whereNotNull('article_id')
            ->when($this->option('creator'), fn ($query, $id) => $query->where('actor_id', $id))
            ->when($this->option('draft-id'), fn ($query, $id) => $query->where('article_id', $id))
            ->get(['article_id', 'actor_id', 'request_hash'])
            ->groupBy(fn ($row) => $row->actor_id.':'.$row->request_hash)
            ->filter(fn ($rows) => $rows->pluck('article_id')->unique()->count() > 1);

        $found = 0;
        $repaired = 0;
        foreach ($operations as $identity => $rows) {
            $ids = $rows->pluck('article_id')->unique()->sort()->values();
            $articles = Article::query()->whereIn('id', $ids)->where('submission_mode', 'direct_publication')
                ->when($this->option('publication'), fn ($query, $id) => $query->where('magazine_id', $id))
                ->orderBy('id')->get();
            if ($articles->count() < 2) {
                continue;
            }

            $found++;
            $canonical = $articles->first();
            $this->warn("Likely duplicate group {$identity}; canonical draft {$canonical->id}; candidates: ".$articles->pluck('id')->join(', '));
            if ($this->option('details')) {
                $this->table(['ID', 'status', 'title', 'publication', 'created', 'updated'], $articles->map(fn (Article $article) => [
                    $article->id, $article->status, $article->title, $article->magazine_id, $article->created_at, $article->updated_at,
                ])->all());
            }

            if (! $this->option('repair')) {
                continue;
            }

            foreach ($articles->slice(1) as $duplicate) {
                if (! $this->isUntouchedDraft($duplicate)) {
                    $this->error("Draft {$duplicate->id} has files, user communication, later edits, or a non-draft status; manual review required.");

                    continue;
                }
                DB::transaction(function () use ($canonical, $duplicate) {
                    $locked = Article::query()->whereKey($duplicate->id)->lockForUpdate()->firstOrFail();
                    $canonicalLocked = Article::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();
                    abort_unless($this->isUntouchedDraft($locked), 409, 'The duplicate changed during the audit.');
                    ArticleAuditLog::create([
                        'article_id' => $canonicalLocked->id,
                        'actor_id' => $canonicalLocked->directly_created_by,
                        'event' => 'direct_publication.duplicate_draft_repaired',
                        'from_status' => $canonicalLocked->status,
                        'to_status' => $canonicalLocked->status,
                        'payload' => ['removed_duplicate_article_id' => $locked->id],
                    ]);
                    $locked->delete();
                });
                $repaired++;
                $this->info("Removed untouched duplicate draft {$duplicate->id}; storage objects were not deleted.");
            }
        }

        if ($found === 0) {
            $this->info('No deterministic duplicate direct-publication draft groups were found.');
        } elseif (! $this->option('repair')) {
            $this->comment('Dry run only. Review the groups and rerun with --repair to remove only untouched duplicates.');
        }
        $this->info("Groups found: {$found}; drafts repaired: {$repaired}.");

        return self::SUCCESS;
    }

    private function isUntouchedDraft(Article $article): bool
    {
        if ($article->status !== 'direct_publication_draft' || $article->files()->exists()) {
            return false;
        }
        $meaningfulAudit = $article->auditLogs()->whereNotIn('event', [
            'direct_publication.created', 'article_thread.created', 'article_thread.system_message_created',
        ])->exists();
        $userMessages = DB::table('article_thread_messages')->join('article_threads', 'article_threads.id', '=', 'article_thread_messages.thread_id')
            ->where('article_threads.article_id', $article->id)->where('article_thread_messages.is_system', false)->exists();

        return ! $meaningfulAudit && ! $userMessages;
    }
}
