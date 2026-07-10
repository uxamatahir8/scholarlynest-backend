<?php

namespace App\Listeners;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\ArticleAuditLog;
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

            'review.accepted' => $this->editorialRecipients($article)->merge($this->superAdmins())->pipe(fn ($items) => $this->dedupe($items)),
            'review.declined' => $this->editorialRecipients($article)->merge($this->superAdmins())->pipe(fn ($items) => $this->dedupe($items)),
            'sub_editor.recommendation_submitted',
            'review.submitted',
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

            default => collect(),
        };
    }

    private function messageFor($article, ArticleWorkflowEventOccurred $event): array
    {
        $title = $article->title;
        $statusLabel = ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::normalize($article->status)] ?? str_replace('_', ' ', $article->status);

        return match ($event->event) {
            'sub_editor.assigned' => [
                'subject' => 'Sub Editor Assignment: ' . $title,
                'body' => [
                    'A Sub Editor assignment has been created for "' . $title . '".',
                    'Current manuscript status: ' . $statusLabel . '.',
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
            'review.submitted' => [
                'subject' => 'Reviewer Report Submitted: ' . $title,
                'body' => ['A reviewer report has been submitted for "' . $title . '".'],
            ],
            'review.reopened' => [
                'subject' => 'Review Reopened: ' . $title,
                'body' => ['A reviewer assignment has been reopened for "' . $title . '".'],
            ],
            'revision.requested' => [
                'subject' => 'Revision Requested: ' . $title,
                'body' => [
                    'A revision has been requested for your manuscript "' . $title . '".',
                    'Please review the author-facing comments in your dashboard.',
                ],
            ],
            'article.accepted' => [
                'subject' => 'Article Accepted: ' . $title,
                'body' => ['The manuscript "' . $title . '" has been accepted.'],
            ],
            'article.rejected' => [
                'subject' => 'Article Decision: ' . $title,
                'body' => ['A final editorial decision has been recorded for "' . $title . '".'],
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
                'subject' => 'Article Resubmitted: ' . $title,
                'body' => ['The manuscript "' . $title . '" has been resubmitted for editorial review.', 'Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '.', 'Base Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '.', 'Revision Tracking Code: ' . $this->nextRevisionTrackingCode($article) . '.', 'Submitting author: ' . ($article->user?->name ?? 'Not recorded') . '.', 'Submitted: ' . now()->toDateTimeString() . '.', 'Next action: review the revised manuscript and continue editorial assessment.'],
            ],
            default => [
                'subject' => 'Workflow Update: ' . $title,
                'body' => ['A workflow update has been recorded for "' . $title . '".'],
            ],
        };
    }

    private function nextRevisionTrackingCode($article): string { return ($article->tracking_code ?? 'Not assigned') . '-R' . max(1, (int) $article->versions()->max('version_number')); }

    private function subEditorRecipients($article): Collection { return collect($article->subEditorAssignments()->with('subEditor')->get()->map(fn ($a) => $this->userRecipient($a->subEditor, 'sub_editor'))->all()); }

    private function reviewerResponseMessage($article, ArticleWorkflowEventOccurred $event): array
    { $reviewer = $event->actor; $accepted = $event->event === 'review.accepted'; return ['subject' => 'Reviewer ' . ($accepted ? 'Accepted' : 'Declined') . ' Invitation: ' . ($reviewer?->name ?? 'Reviewer') . ' — ' . $article->title, 'body' => ['Reviewer: ' . ($reviewer?->name ?? 'Reviewer') . ' (' . ($reviewer?->email ?? 'email unavailable') . ').', 'Response: ' . ($accepted ? 'Accepted' : 'Declined') . '.', 'Article: ' . $article->title . '.', 'Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest') . '.', 'Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '.', 'Timestamp: ' . now()->toDateTimeString() . '.']]; }

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
