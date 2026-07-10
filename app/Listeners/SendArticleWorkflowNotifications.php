<?php

namespace App\Listeners;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\ArticleAuditLog;
use App\Models\Magazine;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendArticleWorkflowNotifications implements ShouldQueue
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    public function handle(ArticleWorkflowEventOccurred $event): void
    {
        $article = $event->article->fresh(['user', 'magazine', 'articleAuthors']);
        if (!$article) {
            return;
        }

        $recipients = $this->recipientsFor($article, $event);
        if ($recipients->isEmpty()) {
            return;
        }

        if ($this->wasTransitionAlreadyNotified($article->id, $event)) {
            return;
        }

        $message = $this->messageFor($article, $event);
        $message['body'] = array_merge($message['body'], $this->workflowContextLines($article, $event));
        $action = [
            'text' => 'Open Workflow',
            'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . '/admin/articles',
        ];

        foreach ($recipients as $recipient) {
            $this->notificationService->send(
                $recipient['email'],
                $message['subject'],
                $recipient['name'] ? 'Dear ' . $recipient['name'] . ',' : 'Hello,',
                $message['body'],
                $action,
                'default',
                $recipient['user_id'] ?? null
            );
        }

        ArticleAuditLog::create([
            'article_id' => $article->id,
            'actor_id' => $event->actor?->id,
            'event' => 'notification.sent',
            'from_status' => $event->payload['from_status'] ?? $article->status,
            'to_status' => $event->payload['to_status'] ?? $article->status,
            'payload' => [
                'workflow_event' => $event->event,
                'recipient_count' => $recipients->count(),
                'recipient_types' => $recipients->pluck('type')->unique()->values()->all(),
            ],
        ]);
    }

    private function wasTransitionAlreadyNotified(int $articleId, ArticleWorkflowEventOccurred $event): bool
    {
        return ArticleAuditLog::query()
            ->where('article_id', $articleId)
            ->where('event', 'notification.sent')
            ->where('from_status', $event->payload['from_status'] ?? null)
            ->where('to_status', $event->payload['to_status'] ?? null)
            ->get()
            ->contains(fn (ArticleAuditLog $log) => ($log->payload['workflow_event'] ?? null) === $event->event);
    }

    private function recipientsFor($article, ArticleWorkflowEventOccurred $event): Collection
    {
        return match ($event->event) {
            'sub_editor.assigned' => $this->dedupe($this->authorRecipients($article)->merge(collect([
                $this->userRecipient($event->payload['sub_editor'] ?? null, 'sub_editor'),
                $this->userRecipient($event->actor, 'assigner'),
            ])->merge($this->superAdmins()))),

            'reviewer.assigned' => $this->dedupe($this->authorRecipients($article)->merge(collect([
                $this->userRecipient($event->payload['reviewer'] ?? null, 'reviewer'),
                $this->userRecipient($event->actor, 'assigner'),
            ])->merge($this->superAdmins()))),

            'review.accepted', 'review.declined', 'review.submitted' => $this->editorialRecipients($article)->merge($this->subEditorRecipients($article))->merge($this->superAdmins())->pipe(fn ($items) => $this->dedupe($items)),
            'sub_editor.recommendation_submitted',
            'review.reopened' => $this->editorialRecipients($article)->merge($this->superAdmins())->pipe(fn ($items) => $this->dedupe($items)),

            'revision.requested' => $this->authorRecipients($article),
            'article.under_review' => $this->authorRecipients($article),

            'article.accepted',
            'article.rejected' => $this->authorRecipients($article)
                ->merge($this->editorialRecipients($article))
                ->merge($this->superAdmins())
                ->pipe(fn ($items) => $this->dedupe($items)),

            'production.assigned' => $this->dedupe($this->authorRecipients($article)->merge(collect([
                $this->userRecipient($event->payload['assignee'] ?? null, 'production_assignee'),
                $this->userRecipient($event->actor, 'assigner'),
            ])->merge($this->superAdmins()))),

            'production.completed',
            'article.ready_for_publication',
            'post_publication.recorded' => $this->authorRecipients($article)
                ->merge($this->editorialRecipients($article))
                ->merge($this->publisherRecipients($article))
                ->merge($this->superAdmins())
                ->pipe(fn ($items) => $this->dedupe($items)),

            'article.published' => $this->authorRecipients($article)
                ->merge($this->editorialRecipients($article))
                ->merge($this->publisherRecipients($article))
                ->merge($this->superAdmins())
                ->pipe(fn ($items) => $this->dedupe($items)),

            'article.resubmitted' => $this->editorialRecipients($article)->merge($this->subEditorRecipients($article))->merge($this->authorRecipients($article))->merge($this->superAdmins())->pipe(fn ($items) => $this->dedupe($items)),

            'transfer.requested' => $this->authorRecipients($article),
            'transfer.accepted' => $this->authorRecipients($article)
                ->merge($this->transferRequestedByRecipient($event))
                ->merge($this->editorialRecipients($article))
                ->merge($this->superAdmins())
                ->pipe(fn ($items) => $this->dedupe($items)),
            'transfer.rejected' => $this->authorRecipients($article)
                ->merge($this->transferRequestedByRecipient($event))
                ->merge($this->superAdmins())
                ->pipe(fn ($items) => $this->dedupe($items)),

            default => collect(),
        };
    }

    private function messageFor($article, ArticleWorkflowEventOccurred $event): array
    {
        $title = $article->title;
        $statusLabel = ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::normalize($article->status)] ?? str_replace('_', ' ', $article->status);

        return match ($event->event) {
            'sub_editor.assigned' => [
                'subject' => 'Sub Editor Assigned: ' . $title,
                'body' => [
                    'You have been assigned as Sub Editor for an article.',
                    'Article Details: Article Title: ' . $title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Assigned By: ' . ($event->actor?->name ?? 'System workflow') . '. Assigned At: ' . now()->toDateTimeString() . '. Current Status: ' . $statusLabel . '.',
                    'Abstract: ' . strip_tags((string) ($article->abstract ?? 'Not provided.')),
                    'Next Action: Please review the article details and continue with the assigned editorial responsibilities.',
                ],
            ],
            'reviewer.assigned' => [
                'subject' => 'Reviewer Assignment: ' . $title,
                'body' => [
                    'A reviewer invitation has been created for "' . $title . '".',
                    'Current manuscript status: ' . $statusLabel . '.',
                ],
            ],
            'article.under_review' => [
                'subject' => 'Article Under Review: ' . $title,
                'body' => [
                    'Your manuscript "' . $title . '" has moved into editorial review.',
                    'Current manuscript status: ' . $statusLabel . '.',
                ],
            ],
            'review.accepted', 'review.declined' => $this->reviewerResponseMessage($article, $event),
            'sub_editor.recommendation_submitted' => [
                'subject' => 'Sub Editor Recommendation Submitted: ' . $title,
                'body' => ['A Sub Editor recommendation has been submitted for "' . $title . '".'],
            ],
            'review.submitted' => $this->reviewSubmittedMessage($article, $event),
            'review.reopened' => [
                'subject' => 'Review Reopened: ' . $title,
                'body' => ['A reviewer assignment has been reopened for "' . $title . '".'],
            ],
            'revision.requested' => [
                'subject' => 'Revision Required: ' . $title . ' — ' . ($article->tracking_code ?? 'Not assigned'),
                'body' => [
                    'The editorial team has requested revisions for your article.',
                    'Article Details: Article Title: ' . $title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Current Status: Revision Required. Decision Date: ' . now()->toDateTimeString() . '.',
                    'Revision Notes: ' . strip_tags((string) ($article->rejection_reason ?? 'Please review the author-visible comments in your workflow.')),
                    'Next Action: Please revise your article and submit the updated manuscript from your article workflow page. After resubmission, the system creates a revision tracking code ending in -R1.',
                ],
            ],
            'article.accepted' => [
                'subject' => 'Article Accepted: ' . $title . ' — ' . ($article->tracking_code ?? 'Not assigned'),
                'body' => ['Congratulations. Your article has been accepted.', 'Article Details: Article Title: ' . $title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Accepted At: ' . now()->toDateTimeString() . '. Current Status: ' . $statusLabel . '.', 'Next Action: The article will now proceed to the next production or author final review step according to the editorial workflow.'],
            ],
            'article.rejected' => [
                'subject' => 'Article Decision: ' . $title . ' — ' . ($article->tracking_code ?? 'Not assigned'),
                'body' => ['After editorial review, a decision has been made on your article.', 'Article Details: Article Title: ' . $title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Decision: Rejected. Decision Date: ' . now()->toDateTimeString() . '.', 'Decision Notes: ' . strip_tags((string) ($article->rejection_reason ?? 'No author-visible decision notes were recorded.')), 'Thank you for submitting your work to Scholarly Nest.'],
            ],
            'production.assigned' => [
                'subject' => 'Production Assignment: ' . $title,
                'body' => ['A production assignment has been created for "' . $title . '".'],
            ],
            'production.completed' => [
                'subject' => 'Production Task Completed: ' . $title,
                'body' => ['A production task has been completed for "' . $title . '".'],
            ],
            'article.ready_for_publication' => [
                'subject' => 'Article Ready for Publication: ' . $title,
                'body' => ['The manuscript "' . $title . '" is ready for publication.'],
            ],
            'article.published' => [
                'subject' => 'Article Published: ' . $title,
                'body' => ['The article "' . $title . '" has been published.'],
            ],
            'post_publication.recorded' => [
                'subject' => 'Post-Publication Action Recorded: ' . $title,
                'body' => ['A post-publication action has been recorded for "' . $title . '".'],
            ],
            'article.resubmitted' => [
                'subject' => 'Article Resubmitted: ' . $title . ' — ' . $this->nextRevisionTrackingCode($article),
                'body' => [($article->user?->name ?? $article->user?->email ?? 'The submitting author') . ' submitted a revised version of the article.', 'Article Details: Article Title: ' . $title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Base Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Revision Tracking Code: ' . $this->nextRevisionTrackingCode($article) . '. Revision Number: ' . max(1, (int) $article->versions()->max('version_number')) . '. Submitted By: ' . ($article->user?->name ?? $article->user?->email ?? 'Not recorded') . '. Submitted At: ' . now()->toDateTimeString() . '. Current Status: ' . $statusLabel . '.', 'Change Summary: ' . strip_tags((string) ($article->change_summary ?? 'No change summary supplied.')), 'Next Action: Please review the revised manuscript and continue the editorial workflow.'],
            ],
            'transfer.requested' => $this->transferRequestedMessage($article, $event),
            'transfer.accepted' => $this->transferAcceptedMessage($article, $event),
            'transfer.rejected' => $this->transferRejectedMessage($article, $event),
            default => [
                'subject' => 'Workflow Update: ' . $title,
                'body' => ['A workflow update has been recorded for "' . $title . '".'],
            ],
        };
    }

    private function nextRevisionTrackingCode($article): string { return ($article->tracking_code ?? 'Not assigned') . '-R' . max(1, (int) $article->versions()->max('version_number')); }

    private function subEditorRecipients($article): Collection { return collect($article->subEditorAssignments()->with('subEditor')->get()->map(fn ($a) => $this->userRecipient($a->subEditor, 'sub_editor'))->all()); }

    private function reviewerResponseMessage($article, ArticleWorkflowEventOccurred $event): array
    { $reviewer = $event->actor; $accepted = $event->event === 'review.accepted'; $name = $reviewer?->name ?? ($event->payload['reviewer_name'] ?? 'Reviewer'); $email = $reviewer?->email ?? ($event->payload['reviewer_email'] ?? 'email unavailable'); return ['subject' => 'Reviewer ' . ($accepted ? 'Accepted' : 'Declined') . ' Invitation: ' . $name . ' — ' . $article->title, 'body' => ['A reviewer has ' . ($accepted ? 'accepted' : 'declined') . ' the review invitation.', 'Reviewer Details: Reviewer Name: ' . $name . '. Reviewer Email: ' . $email . '. Response: ' . ($accepted ? 'Accepted' : 'Declined') . '. Responded At: ' . now()->toDateTimeString() . '.', 'Article Details: Article Title: ' . $article->title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '.', 'Next Action: ' . ($accepted ? 'The reviewer can now access permitted manuscript files and submit their recommendation from the reviewer dashboard.' : 'Please assign another reviewer or continue the editorial workflow according to your review policy.')]]; }

    private function reviewSubmittedMessage($article, ArticleWorkflowEventOccurred $event): array { $reviewer = $event->actor; return ['subject' => 'Review Submitted: ' . ($reviewer?->name ?? 'Reviewer') . ' — ' . $article->title, 'body' => ['A reviewer has submitted their review.', 'Reviewer Details: Reviewer Name: ' . ($reviewer?->name ?? 'Reviewer') . '. Reviewer Email: ' . ($reviewer?->email ?? 'email unavailable') . '. Recommendation: ' . ($event->payload['recommendation'] ?? 'Not recorded') . '. Submitted At: ' . now()->toDateTimeString() . '.', 'Article Details: Article Title: ' . $article->title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '.', 'Next Action: Please review the recommendation and continue the editorial decision process.', 'Privacy Note: Reviewer comments and confidential recommendations are visible only to authorized editorial users.']]; }

    private function transferRequestedMessage($article, ArticleWorkflowEventOccurred $event): array
    {
        $fromMagazine = $this->magazineTitle($event->payload['from_magazine_id'] ?? null, $article->magazine?->title);
        $toMagazine = $this->magazineTitle($event->payload['to_magazine_id'] ?? null, $event->payload['target_magazine'] ?? 'the suggested magazine');

        return [
            'subject' => 'Magazine Transfer Request: ' . $article->title,
            'body' => [
                'The editor of ' . $fromMagazine . ' feels that your article may be more suitable for ' . $toMagazine . '.',
                'Article Details: Article Title: ' . $article->title . '. Current Magazine: ' . $fromMagazine . '. Suggested Magazine: ' . $toMagazine . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Requested By: ' . ($event->actor?->name ?? 'Editorial team') . '. Requested At: ' . now()->toDateTimeString() . '.',
                'Editor Comments: ' . strip_tags((string) ($event->payload['editor_comments'] ?? 'No comments provided.')),
                'Please log in to your Scholarly Nest account to accept or reject this transfer request.',
            ],
        ];
    }

    private function transferAcceptedMessage($article, ArticleWorkflowEventOccurred $event): array
    {
        $fromMagazine = $this->magazineTitle($event->payload['from_magazine_id'] ?? null, 'Original magazine');
        $toMagazine = $this->magazineTitle($event->payload['to_magazine_id'] ?? null, $article->magazine?->title);

        return [
            'subject' => 'Magazine Transfer Accepted: ' . $article->title,
            'body' => [
                'The author accepted the magazine transfer request.',
                'Article Details: Article Title: ' . $article->title . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Old Magazine: ' . $fromMagazine . '. New Magazine: ' . $toMagazine . '. Accepted By: ' . ($event->actor?->name ?? 'Author') . '. Accepted At: ' . now()->toDateTimeString() . '.',
                'Next Action: The article has returned to Screening in the new magazine.',
            ],
        ];
    }

    private function transferRejectedMessage($article, ArticleWorkflowEventOccurred $event): array
    {
        $fromMagazine = $this->magazineTitle($event->payload['from_magazine_id'] ?? null, $article->magazine?->title);
        $toMagazine = $this->magazineTitle($event->payload['to_magazine_id'] ?? null, 'Suggested magazine');

        return [
            'subject' => 'Magazine Transfer Rejected: ' . $article->title,
            'body' => [
                'The author rejected the magazine transfer request.',
                'Article Details: Article Title: ' . $article->title . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Original Magazine: ' . $fromMagazine . '. Suggested Magazine: ' . $toMagazine . '. Rejected By: ' . ($event->actor?->name ?? 'Author') . '. Rejected At: ' . now()->toDateTimeString() . '.',
                'Rejection Reason: ' . strip_tags((string) ($event->payload['author_rejection_reason'] ?? 'No reason provided.')),
                'Next Action: The article remains in Screening in the original magazine.',
            ],
        ];
    }

    private function workflowContextLines($article, ArticleWorkflowEventOccurred $event): array
    {
        $status = ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::normalize($article->status)] ?? str_replace('_', ' ', $article->status);
        $actor = $event->actor?->name ?? 'System workflow';
        return [
            'Article Details: Title: ' . $article->title . '. Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '.',
            'Current Status: ' . $status . '. Actor: ' . $actor . '. Timestamp: ' . now()->toDateTimeString() . '.',
            'Next Action: Open the workflow to review the current stage and complete your authorized action.',
        ];
    }

    private function authorRecipients($article): Collection
    {
        return collect([
            $this->userRecipient($article->user, 'article_owner'),
        ])->merge(
            $article->articleAuthors
                ->filter(fn ($author) => $author->is_corresponding)
                ->map(fn ($author) => [
                    'email' => $author->co_author_email,
                    'name' => $author->co_author_name,
                    'user_id' => $author->user_id,
                    'type' => 'corresponding_author',
                ])
        )->pipe(fn ($items) => $this->dedupe($items));
    }

    private function editorialRecipients($article): Collection
    {
        return collect(User::query()
            ->whereHas('magazines', function ($query) use ($article) {
                $query->where('magazines.id', $article->magazine_id)
                    ->where(function ($pivotQuery) {
                        $pivotQuery->whereIn('magazine_user.role', ['editor', 'magazine_editor'])
                            ->orWhereNull('magazine_user.role');
                    });
            })
            ->get()
            ->map(fn (User $user) => $this->userRecipient($user, 'editor'))
            ->all());
    }

    private function transferRequestedByRecipient(ArticleWorkflowEventOccurred $event): Collection
    {
        $userId = $event->payload['requested_by_user_id'] ?? null;
        if (!$userId) {
            return collect();
        }

        return collect([$this->userRecipient(User::find($userId), 'requesting_editor')]);
    }

    private function publisherRecipients($article): Collection
    {
        return collect(User::query()
            ->whereHas('magazines', function ($query) use ($article) {
                $query->where('magazines.id', $article->magazine_id)
                    ->where('magazine_user.role', 'publisher');
            })
            ->get()
            ->map(fn (User $user) => $this->userRecipient($user, 'publisher'))
            ->all());
    }

    private function superAdmins(): Collection
    {
        return collect(User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'super_admin'))
            ->get()
            ->map(fn (User $user) => $this->userRecipient($user, 'super_admin'))
            ->all());
    }

    private function magazineTitle(?int $magazineId, ?string $fallback): string
    {
        if (!$magazineId) {
            return $fallback ?: 'ScholarlyNest';
        }

        return Magazine::find($magazineId)?->title ?: ($fallback ?: 'ScholarlyNest');
    }

    private function userRecipient(?User $user, string $type): ?array
    {
        if (!$user || !$user->email) {
            return null;
        }

        return [
            'email' => $user->email,
            'name' => $user->name,
            'user_id' => $user->id,
            'type' => $type,
        ];
    }

    private function dedupe(Collection $recipients): Collection
    {
        return $recipients
            ->filter(fn ($recipient) => !empty($recipient['email']))
            ->unique(fn ($recipient) => strtolower($recipient['email']))
            ->values();
    }
}
