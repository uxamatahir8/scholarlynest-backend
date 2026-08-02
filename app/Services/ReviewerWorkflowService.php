<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleReviewRound;
use App\Models\ArticleAuditLog;
use App\Models\ReviewerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Notifications\NotificationEventRecorder;

class ReviewerWorkflowService
{
    public function invite(Article $article, User $actor, int $versionId, int $reviewRoundId, int $roundNumber, ?int $reviewerId, ?string $name, ?string $email, ?string $dueAt, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($article, $actor, 'invite-reviewer', $key, ['article_version_id' => $versionId, 'review_round_id' => $reviewRoundId, 'round_number' => $roundNumber, 'reviewer_id' => $reviewerId], 'reviewer.invited', 'reviewer.invited',
            function (Article $locked) use ($actor, $versionId, $reviewRoundId, $roundNumber, $reviewerId, $name, $email, $dueAt, $key) {
                $version = $locked->versions()->whereKey($versionId)->lockForUpdate()->first();
                if (! $version || (int) $locked->current_version_id !== $versionId) {
                    app(ArticleLifecycleService::class)->conflict('Reviewers can only be invited to the current article version.');
                }
                if ($version->screening_status !== 'passed') {
                    app(ArticleLifecycleService::class)->conflict('Reviewer invitation is prohibited until this version passes screening.');
                }
                $round = ArticleReviewRound::query()->whereKey($reviewRoundId)
                    ->where('article_id', $locked->id)->where('article_version_id', $versionId)
                    ->where('round_number', $roundNumber)->where('status', ArticleReviewRound::OPEN)
                    ->lockForUpdate()->first();
                if (! $round) {
                    app(ArticleLifecycleService::class)->conflict('The selected review round is not open for this version.');
                }
                $normalizedEmail = strtolower(trim((string) ($email ?: User::find($reviewerId)?->email)));
                if (! $reviewerId && ! $normalizedEmail) {
                    app(ArticleLifecycleService::class)->conflict('A reviewer or invitation email is required.');
                }
                $duplicate = ReviewerAssignment::query()->where('article_version_id', $versionId)->where('review_round_id', $reviewRoundId)->where('round_number', $roundNumber)
                    ->whereNull('revoked_at')->where(function ($query) {
                        $query->whereIn('status', ['accepted', 'in_progress', 'completed'])
                            ->orWhere(function ($pending) {
                                $pending->whereIn('status', ['pending', 'invited'])
                                    ->where(fn ($expiry) => $expiry->whereNull('invite_expires_at')->orWhere('invite_expires_at', '>', now()));
                            });
                    })
                    ->where(function ($query) use ($reviewerId, $normalizedEmail) {
                        if ($reviewerId) {
                            $query->where('reviewer_id', $reviewerId);
                        }
                        if ($normalizedEmail) {
                            $query->orWhere('invitee_email', $normalizedEmail);
                        }
                    })->exists();
                if ($duplicate) {
                    app(ArticleLifecycleService::class)->conflict('An active invitation already exists for this reviewer and version.');
                }
                $rawToken = Str::random(64);
                $assignment = ReviewerAssignment::create([
                    'article_id' => $locked->id, 'article_version_id' => $versionId, 'review_round_id' => $reviewRoundId, 'round_number' => $roundNumber,
                    'reviewer_id' => $reviewerId, 'invitee_name' => $name ?: User::find($reviewerId)?->name,
                    'invitee_email' => $normalizedEmail, 'invite_token_hash' => hash('sha256', $rawToken),
                    'invited_at' => now(), 'invite_expires_at' => now()->addDays(14), 'assigned_by' => $actor->id,
                    'status' => 'invited', 'due_date' => $dueAt, 'idempotency_key' => $key,
                ]);
                DB::afterCommit(fn () => app(ReviewerInvitationDeliveryService::class)->send($assignment->id, $rawToken));

                return ['assignment_id' => $assignment->id, 'article_version_id' => $versionId];
            });
    }

    public function respond(ReviewerAssignment $assignment, User $actor, bool $accept, ?string $reason, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($assignment->article, $actor, $accept ? 'accept-review' : 'decline-review', $key, ['assignment_id' => $assignment->id], $accept ? 'review.accepted' : 'review.declined', $accept ? 'review.accepted' : 'review.declined',
            function () use ($assignment, $actor, $accept, $reason) {
                $locked = ReviewerAssignment::query()->with(['version', 'reviewRound'])->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if ((int) $locked->reviewer_id !== (int) $actor->id || $locked->revoked_at) {
                    app(ArticleLifecycleService::class)->conflict('This review invitation is not active for the current user.');
                }
                if (! $locked->version || ! $locked->reviewRound
                    || (int) $locked->reviewRound->article_version_id !== (int) $locked->article_version_id
                    || (int) $locked->reviewRound->article_id !== (int) $locked->article_id) {
                    app(ArticleLifecycleService::class)->conflict('The review assignment version or review round is invalid.');
                }
                if ($accept && in_array($locked->status, ['accepted', 'in_progress', 'review_in_progress', 'reopened'], true)) {
                    app(ReviewerQuestionnaireService::class)->ensure($locked);

                    return ['assignment_id' => $locked->id, 'status' => $locked->status];
                }
                if (! $accept && $locked->status === 'declined') {
                    return ['assignment_id' => $locked->id, 'status' => 'declined'];
                }
                if (! in_array($locked->status, ['pending', 'invited'], true)) {
                    app(ArticleLifecycleService::class)->conflict('This invitation has already been answered.');
                }
                if ($locked->invite_expires_at?->isPast()) {
                    app(ArticleLifecycleService::class)->conflict('This invitation has expired.');
                }
                $locked->update($accept
                    ? ['status' => 'accepted', 'accepted_at' => now(), 'invite_token_hash' => null]
                    : ['status' => 'declined', 'declined_at' => now(), 'decline_reason' => $reason, 'invite_token_hash' => null]);
                if ($accept) {
                    app(ReviewerQuestionnaireService::class)->ensure($locked->fresh());
                }

                return ['assignment_id' => $locked->id, 'status' => $locked->status];
            });
    }

    public function submit(ReviewerAssignment $assignment, User $actor, string $recommendation, string $authorComments, ?string $confidentialComments, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($assignment->article, $actor, 'submit-review', $key, ['assignment_id' => $assignment->id, 'recommendation' => $recommendation], 'review.submitted', 'review.submitted',
            function () use ($assignment, $actor, $recommendation, $authorComments, $confidentialComments, $key) {
                $locked = ReviewerAssignment::query()->with(['version', 'reviewRound'])->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if ((int) $locked->reviewer_id !== (int) $actor->id || ! app(ReviewerQuestionnaireService::class)->canAccess($locked)) {
                    app(ArticleLifecycleService::class)->conflict('The review is not open for submission.');
                }
                if (! $locked->version || ! $locked->reviewRound
                    || (int) $locked->reviewRound->article_version_id !== (int) $locked->article_version_id) {
                    app(ArticleLifecycleService::class)->conflict('The review assignment version or review round is invalid.');
                }
                if ($locked->completed_at) {
                    app(ArticleLifecycleService::class)->conflict('A submitted review is immutable unless formally reopened.');
                }
                $decisionExists = $locked->article->editorialDecisions()->where('article_version_id', $locked->article_version_id)->exists();
                $locked->update(['status' => 'completed', 'completed_at' => now(), 'recommendation' => $recommendation, 'comments_for_author' => $authorComments, 'confidential_comments' => $confidentialComments, 'submitted_after_decision' => $decisionExists, 'editorial_decision_existed_at_submission' => $decisionExists]);
                if ($decisionExists) {
                    $audit = ArticleAuditLog::create([
                        'article_id' => $locked->article_id,
                        'actor_id' => $actor->id,
                        'event' => 'review.submitted_after_decision',
                        'from_status' => $locked->article->status,
                        'to_status' => $locked->article->status,
                        'payload' => ['article_version_id' => $locked->article_version_id, 'review_round_id' => $locked->review_round_id, 'reviewer_assignment_id' => $locked->id],
                    ]);
                    app(NotificationEventRecorder::class)->record(
                        'review.submitted_after_decision', $locked->article, $actor,
                        ['article_version_id' => $locked->article_version_id, 'review_round_id' => $locked->review_round_id, 'assignment_id' => $locked->id],
                        'reviewer_assignment', $locked->id,
                        deduplicationKey: "review-submitted-after-decision:{$locked->id}:{$key}",
                        articleAuditLogId: $audit->id,
                    );
                }

                return ['assignment_id' => $locked->id, 'status' => 'completed'];
            });
    }

    public function start(ReviewerAssignment $assignment, User $actor, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($assignment->article, $actor, 'start-review', $key, ['assignment_id' => $assignment->id], 'review.started', 'review.started', function () use ($assignment, $actor) {
            $locked = ReviewerAssignment::query()->with(['version', 'reviewRound'])->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertAccessibleAssignment($locked, $actor, ['accepted', 'in_progress', 'review_in_progress', 'reopened']);
            if ($locked->status === 'accepted') {
                $locked->update(['status' => 'in_progress', 'started_at' => $locked->started_at ?: now()]);
            }
            app(ReviewerQuestionnaireService::class)->ensure($locked->fresh());

            return ['assignment_id' => $locked->id, 'status' => $locked->fresh()->status];
        });
    }

    public function saveDraft(ReviewerAssignment $assignment, User $actor, ?string $recommendation, ?string $authorComments, ?string $confidentialComments, array $responses, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($assignment->article, $actor, 'save-review-draft', $key, ['assignment_id' => $assignment->id], 'review.draft_saved', 'review.draft_saved', function () use ($assignment, $actor, $recommendation, $authorComments, $confidentialComments, $responses) {
            $locked = ReviewerAssignment::query()->with(['version', 'reviewRound'])->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertAccessibleAssignment($locked, $actor, ['accepted', 'in_progress', 'review_in_progress', 'reopened']);
            $locked->update([
                'status' => 'in_progress',
                'started_at' => $locked->started_at ?: now(),
                'recommendation' => $recommendation,
                'comments_for_author' => $authorComments,
                'confidential_comments' => $confidentialComments,
            ]);
            app(ReviewerQuestionnaireService::class)->saveDraftResponses($locked->fresh(), $responses);

            return ['assignment_id' => $locked->id, 'status' => 'in_progress'];
        });
    }

    public function reopen(ReviewerAssignment $assignment, User $actor, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($assignment->article, $actor, 'reopen-review', $key, ['assignment_id' => $assignment->id], 'review.reopened', 'review.reopened', function () use ($assignment, $actor) {
            $locked = ReviewerAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'completed') {
                app(ArticleLifecycleService::class)->conflict('Only a completed review can be reopened.');
            }
            $locked->update(['status' => 'in_progress', 'completed_at' => null, 'reopened_at' => now(), 'reopened_by' => $actor->id]);

            return ['assignment_id' => $locked->id, 'status' => 'in_progress'];
        });
    }

    private function assertAccessibleAssignment(ReviewerAssignment $assignment, User $actor, array $statuses): void
    {
        if ((int) $assignment->reviewer_id !== (int) $actor->id || $assignment->revoked_at || $assignment->closed_at || ! in_array($assignment->status, $statuses, true)) {
            app(ArticleLifecycleService::class)->conflict('The review assignment is not available to the current reviewer.');
        }
        if (! $assignment->version || ! $assignment->reviewRound
            || (int) $assignment->reviewRound->article_version_id !== (int) $assignment->article_version_id
            || (int) $assignment->reviewRound->article_id !== (int) $assignment->article_id) {
            app(ArticleLifecycleService::class)->conflict('The review assignment version or review round is invalid.');
        }
    }
}
