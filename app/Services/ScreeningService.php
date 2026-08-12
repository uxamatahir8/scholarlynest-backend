<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleReviewRound;
use App\Models\EditorialDecision;
use App\Models\User;

class ScreeningService
{
    public function decide(Article $article, User $actor, int $versionId, string $decision, ?string $reason, string $key): array
    {
        $event = $decision === 'reject' ? 'article.desk_rejected' : 'article.under_review';

        return app(ArticleLifecycleService::class)->command($article, $actor, 'screen', $key, compact('versionId', 'decision'), 'screening.'.$decision, $event,
            function (Article $locked) use ($actor, $versionId, $decision, $reason) {
                $version = $locked->versions()->whereKey($versionId)->lockForUpdate()->first();
                if (! $version || (int) $locked->current_version_id !== $versionId) {
                    app(ArticleLifecycleService::class)->conflict('The selected article version is not the current submission.');
                }
                if ($version->screening_status !== 'pending') {
                    app(ArticleLifecycleService::class)->conflict('Screening has already been completed for this version.');
                }
                if ($decision === 'reject' && ! trim((string) $reason)) {
                    app(ArticleLifecycleService::class)->conflict('A desk-rejection reason is required.');
                }
                $version->update(['screening_status' => $decision === 'reject' ? 'rejected' : 'passed', 'screened_at' => now(), 'screened_by' => $actor->id]);
                $locked->update(['screened_at' => now(), 'screened_by' => $actor->id, 'status' => $decision === 'reject' ? ArticleStatus::REJECTED : ArticleStatus::UNDER_REVIEW, 'rejection_reason' => $decision === 'reject' ? $reason : null]);
                $round = app(ArticleReviewRoundService::class)->ensureForSubmittedVersion($locked->fresh(), $version->fresh(), $actor);
                if ($decision === 'reject') {
                    $round->update(['status' => ArticleReviewRound::CLOSED, 'closed_at' => now()]);
                    EditorialDecision::create(['article_id' => $locked->id, 'article_version_id' => $version->id, 'round_number' => 1, 'decision_by' => $actor->id, 'decision' => 'rejected', 'decision_source' => 'screening', 'decision_date' => now(), 'comments_for_author' => $reason]);
                }

                return ['version_id' => $version->id, 'decision' => $decision];
            });
    }
}
