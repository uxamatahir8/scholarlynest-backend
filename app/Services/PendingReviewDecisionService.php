<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleVersion;
use App\Models\ReviewerAssignment;
use App\Models\User;
use App\Services\Notifications\NotificationEventRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;

class PendingReviewDecisionService
{
    public const PENDING_STATUSES = ['accepted', 'in_progress', 'review_in_progress', 'reopened'];

    public function pending(int $articleId, int $versionId, bool $lock = false): Collection
    {
        $query = ReviewerAssignment::query()
            ->where('article_id', $articleId)
            ->where('article_version_id', $versionId)
            ->whereIn('status', self::PENDING_STATUSES)
            ->whereNull('completed_at')
            ->whereNull('closed_at')
            ->whereNull('revoked_at')
            ->with('reviewer:id,name');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function requireConfirmationWhenNeeded(Article $article, ArticleVersion $version, ?string $policy, ?string $reason, ?User $actor = null, ?string $attemptKey = null): void
    {
        $pending = $this->pending($article->id, $version->id);
        if ($pending->isEmpty()) {
            return;
        }
        if (! in_array($policy, ['keep_open', 'close_pending'], true)) {
            if ($actor) {
                $audit = ArticleAuditLog::create([
                    'article_id' => $article->id,
                    'actor_id' => $actor->id,
                    'event' => 'editorial.decision_blocked_pending_reviews',
                    'from_status' => $article->status,
                    'to_status' => $article->status,
                    'payload' => [
                        'article_version_id' => $version->id,
                        'pending_review_count' => $pending->count(),
                        'pending_assignment_ids' => $pending->modelKeys(),
                    ],
                ]);
                app(NotificationEventRecorder::class)->record(
                    'editorial_decision.pending_reviews',
                    $article,
                    $actor,
                    ['article_version_id' => $version->id, 'pending_review_count' => $pending->count()],
                    'article_version',
                    $version->id,
                    deduplicationKey: 'editorial-decision-pending:'.$version->id.':'.($attemptKey ?: $audit->id),
                    articleAuditLogId: $audit->id,
                );
            }
            throw new HttpResponseException(response()->json([
                'message' => 'Pending reviewer submissions require confirmation.',
                'code' => 'PENDING_REVIEWS_REQUIRE_CONFIRMATION',
                'requires_confirmation' => true,
                'pending_review_count' => $pending->count(),
                'pending_reviews' => $pending->map(fn (ReviewerAssignment $assignment) => $this->payload($assignment, $version))->values(),
                'available_actions' => ['proceed_keep_open', 'proceed_close_pending'],
            ], 409));
        }
        if (! trim((string) $reason)) {
            throw new HttpResponseException(response()->json([
                'message' => 'A reason for proceeding without pending reviews is required.',
                'errors' => ['pending_review_override_reason' => ['A reason for proceeding without pending reviews is required.']],
            ], 422));
        }
    }

    public function apply(Article $article, ArticleVersion $version, User $actor, ?string $policy, ?string $reason): array
    {
        $pending = $this->pending($article->id, $version->id, true);
        if ($pending->isEmpty()) {
            return ['count' => 0, 'ids' => [], 'policy' => null];
        }
        if (! in_array($policy, ['keep_open', 'close_pending'], true) || ! trim((string) $reason)) {
            $this->requireConfirmationWhenNeeded($article, $version, $policy, $reason, $actor);
        }

        ArticleAuditLog::create([
            'article_id' => $article->id,
            'actor_id' => $actor->id,
            'event' => 'editorial.pending_review_override_confirmed',
            'from_status' => $article->status,
            'to_status' => $article->status,
            'payload' => [
                'article_version_id' => $version->id,
                'pending_review_policy' => $policy,
                'pending_review_override_reason' => trim((string) $reason),
                'pending_review_count' => $pending->count(),
                'pending_assignment_ids' => $pending->modelKeys(),
            ],
        ]);

        foreach ($pending as $assignment) {
            if ($policy === 'close_pending') {
                $assignment->update([
                    'status' => 'closed_without_review',
                    'closed_at' => now(),
                    'closed_by' => $actor->id,
                    'closure_reason' => trim((string) $reason),
                ]);
                ArticleAuditLog::create([
                    'article_id' => $article->id,
                    'actor_id' => $actor->id,
                    'event' => 'review.closed_without_review',
                    'from_status' => $article->status,
                    'to_status' => $article->status,
                    'payload' => [
                        'article_version_id' => $version->id,
                        'review_round_id' => $assignment->review_round_id,
                        'reviewer_assignment_id' => $assignment->id,
                        'closure_reason' => trim((string) $reason),
                    ],
                ]);
            } else {
                ArticleAuditLog::create([
                    'article_id' => $article->id,
                    'actor_id' => $actor->id,
                    'event' => 'review.kept_open_after_decision',
                    'from_status' => $article->status,
                    'to_status' => $article->status,
                    'payload' => [
                        'article_version_id' => $version->id,
                        'review_round_id' => $assignment->review_round_id,
                        'reviewer_assignment_id' => $assignment->id,
                    ],
                ]);
            }
            app(NotificationEventRecorder::class)->record(
                $policy === 'close_pending' ? 'review.closed_without_review' : 'review.decision_proceeded_open',
                $article,
                $actor,
                [
                    'article_version_id' => $version->id,
                    'review_round_id' => $assignment->review_round_id,
                    'assignment_id' => $assignment->id,
                    'recipient_user_id' => $assignment->reviewer_id,
                    'recipient_privacy_variant' => 'reviewer',
                    'version_label' => $this->versionLabel($version),
                ],
                'reviewer_assignment',
                $assignment->id,
                deduplicationKey: "editorial-decision:{$version->id}:{$policy}:reviewer:{$assignment->id}"
            );
        }

        return ['count' => $pending->count(), 'ids' => $pending->modelKeys(), 'policy' => $policy];
    }

    public function versionLabel(ArticleVersion $version): string
    {
        return (int) ($version->revision_number ?? 0) === 0 && ! $version->parent_version_id
            ? 'Initial Submission'
            : 'R'.(int) $version->revision_number;
    }

    private function payload(ReviewerAssignment $assignment, ArticleVersion $version): array
    {
        return [
            'assignment_id' => $assignment->id,
            'reviewer_display_name' => $assignment->reviewer?->name ?: $assignment->invitee_name ?: 'Reviewer',
            'status' => $assignment->status,
            'version_label' => $this->versionLabel($version),
            'due_at' => $assignment->due_date?->toISOString(),
            'last_reminded_at' => $assignment->last_reminded_at?->toISOString(),
        ];
    }
}
