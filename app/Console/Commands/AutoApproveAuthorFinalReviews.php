<?php

namespace App\Console\Commands;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoApproveAuthorFinalReviews extends Command
{
    protected $signature = 'workflow:auto-approve-author-final-reviews';

    protected $description = 'Automatically approve copyedited articles after the 14-day author response window expires.';

    public function handle(): int
    {
        $approved = 0;

        Article::query()
            ->where('status', ArticleStatus::PROOFREADING)
            ->whereNull('author_final_approved_at')
            ->whereNotNull('author_final_review_due_at')
            ->where('author_final_review_due_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($articles) use (&$approved): void {
                foreach ($articles as $candidate) {
                    $article = DB::transaction(function () use ($candidate): ?Article {
                        $article = Article::query()->lockForUpdate()->find($candidate->id);
                        if (!$article
                            || ArticleStatus::normalize($article->status) !== ArticleStatus::PROOFREADING
                            || $article->author_final_approved_at
                            || !$article->author_final_review_due_at
                            || $article->author_final_review_due_at->isFuture()) {
                            return null;
                        }

                        $approvedAt = now();
                        $article->update([
                            'status' => ArticleStatus::READY_FOR_PUBLICATION,
                            'author_final_approved_at' => $approvedAt,
                            'author_final_approved_by' => null,
                            'author_final_auto_approved_at' => $approvedAt,
                            'author_final_review_due_at' => null,
                        ]);

                        ArticleAuditLog::create([
                            'article_id' => $article->id,
                            'actor_id' => null,
                            'event' => 'author.final_review_auto_approved',
                            'from_status' => ArticleStatus::PROOFREADING,
                            'to_status' => ArticleStatus::READY_FOR_PUBLICATION,
                            'payload' => ['response_window_days' => 14],
                        ]);

                        return $article->fresh();
                    });

                    if (!$article) {
                        continue;
                    }

                    event(new ArticleWorkflowEventOccurred($article, 'author.final_review_auto_approved', null, [
                        'from_status' => ArticleStatus::PROOFREADING,
                        'to_status' => ArticleStatus::READY_FOR_PUBLICATION,
                    ]));
                    event(new ArticleWorkflowEventOccurred($article, 'article.ready_for_publication', null, [
                        'from_status' => ArticleStatus::PROOFREADING,
                        'to_status' => ArticleStatus::READY_FOR_PUBLICATION,
                    ]));
                    $approved++;
                }
            });

        $this->info("Author final reviews automatically approved: {$approved}");

        return self::SUCCESS;
    }
}
