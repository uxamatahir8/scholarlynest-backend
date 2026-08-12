<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleReviewRound;
use App\Models\ArticleVersion;
use App\Models\User;

class ArticleReviewRoundService
{
    public function ensureForSubmittedVersion(Article $article, ArticleVersion $version, ?User $actor = null): ArticleReviewRound
    {
        if ((int) $article->current_version_id === (int) $version->id) {
            $article->reviewRounds()
                ->where('article_version_id', '!=', $version->id)
                ->where('status', ArticleReviewRound::OPEN)
                ->update(['status' => ArticleReviewRound::CLOSED, 'closed_at' => now()]);
        }
        $isCurrentVersion = (int) $article->current_version_id === (int) $version->id;
        $isTerminalArticle = in_array(ArticleStatus::normalize($article->status), [
            ArticleStatus::ACCEPTED,
            ArticleStatus::REJECTED,
            ArticleStatus::PUBLISHED,
        ], true);
        $eligibleRevision = (int) ($version->revision_number ?? 0) > 0;
        if ($eligibleRevision && $isCurrentVersion && $version->submitted_at && ! $isTerminalArticle && $version->screening_status !== 'passed') {
            $version->forceFill([
                'screening_status' => 'passed',
                'screened_at' => $version->screened_at ?: now(),
            ])->saveQuietly();
        }

        $open = $this->versionRequiresReview($article, $version);
        $round = ArticleReviewRound::firstOrCreate(
            ['article_version_id' => $version->id, 'round_number' => 1],
            ['article_id' => $article->id, 'status' => $open ? ArticleReviewRound::OPEN : ArticleReviewRound::PENDING]
        );
        if ($open && $round->status !== ArticleReviewRound::OPEN) {
            $round->update([
                'status' => ArticleReviewRound::OPEN,
                'opened_by' => $actor?->id,
                'opened_at' => $round->opened_at ?: now(),
                'closed_at' => null,
            ]);
        }

        return $round->fresh();
    }

    public function versionRequiresReview(Article $article, ArticleVersion $version): bool
    {
        if ((int) $article->current_version_id !== (int) $version->id || ! $version->submitted_at || $version->screening_status !== 'passed') {
            return false;
        }

        return ! in_array(ArticleStatus::normalize($article->status), [
            ArticleStatus::ACCEPTED,
            ArticleStatus::REJECTED,
            ArticleStatus::PUBLISHED,
        ], true);
    }
}
