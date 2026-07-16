<?php

namespace App\Listeners;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\ArticleAuditLog;
use App\Models\Magazine;
use App\Models\ProductionAssignment;
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

        foreach ($recipients as $recipient) {
            $message = $this->messageForRecipient($article, $event, $recipient);
            if (!str_starts_with($event->event, 'transfer.')) {
                $closingLines = collect($message['body'])
                    ->filter(fn ($line) => str_starts_with(strip_tags((string) $line), 'Next Action:') || str_starts_with(strip_tags((string) $line), 'Privacy Note:'))
                    ->values()
                    ->all();
                $mainLines = collect($message['body'])
                    ->reject(fn ($line) => str_starts_with(strip_tags((string) $line), 'Next Action:') || str_starts_with(strip_tags((string) $line), 'Privacy Note:'))
                    ->values()
                    ->all();
                $message['body'] = array_merge($mainLines, $this->workflowContextLines($article, $event), $closingLines);
            }

            $this->notificationService->send(
                $recipient['email'],
                $message['subject'],
                'Dear ' . ($recipient['name'] ?: $this->recipientFallbackName($recipient['type'])) . ',',
                $message['body'],
                $message['action'] ?? $this->actionForRecipient($article, $recipient),
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
            'sub_editor.assigned' => $this->dedupe(collect([
                $this->userRecipient($event->payload['sub_editor'] ?? null, 'sub_editor'),
                $this->userRecipient($event->actor, 'assigner'),
            ])->merge($this->authorRecipients($article))->merge($this->superAdmins())),

            'reviewer.assigned' => $this->dedupe(collect([
                $this->userRecipient($event->actor, 'assigner'),
            ])->merge($this->authorRecipients($article))->merge($this->superAdmins())),

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

            'production.assigned' => $this->dedupe(collect([
                $this->userRecipient($event->payload['assignee'] ?? null, 'production_assignee'),
                $this->userRecipient($event->actor, 'assigner'),
            ])->merge($this->authorRecipients($article))->merge($this->superAdmins())),

            'production.completed',
            'author.final_review_approved',
            'author.final_review_auto_approved',
            'article.ready_for_publication',
            'post_publication.recorded' => $this->authorRecipients($article)
                ->merge($this->editorialRecipients($article))
                ->merge($this->publisherRecipients($article))
                ->merge($this->superAdmins())
                ->pipe(fn ($items) => $this->dedupe($items)),

            'author.final_review_requested' => $this->authorRecipients($article),

            'author.final_review_denied' => $this->authorRecipients($article)
                ->merge($this->copyEditorRecipients($article))
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
                    'You have been assigned as the Sub Editor responsible for this manuscript.',
                    'Assignment Details: Assigned By: ' . ($event->actor?->name ?? 'System workflow') . '. Assigned At: ' . $this->eventTime() . '.',
                    'Manuscript Abstract: ' . strip_tags((string) ($article->abstract ?? 'Not provided.')),
                    'Next Action: Please review the article details and continue with the assigned editorial responsibilities.',
                ],
            ],
            'reviewer.assigned' => [
                'subject' => 'Reviewer Assignment: ' . $title,
                'body' => [
                    'A reviewer invitation has been created and sent for this manuscript.',
                    'Next Action: Monitor the invitation response and assign another reviewer if the invitation is declined or expires.',
                ],
            ],
            'article.under_review' => [
                'subject' => 'Article Under Review: ' . $title,
                'body' => [
                    'Your manuscript has moved into editorial review.',
                    'Next Action: No action is required right now. You can follow its progress from your article dashboard.',
                ],
            ],
            'review.accepted', 'review.declined' => $this->reviewerResponseMessage($article, $event),
            'sub_editor.recommendation_submitted' => [
                'subject' => 'Sub Editor Recommendation Submitted: ' . $title,
                'body' => [
                    'A Sub Editor has submitted an editorial recommendation for this manuscript.',
                    'Next Action: Review the recommendation and continue the editorial decision process.',
                ],
            ],
            'review.submitted' => $this->reviewSubmittedMessage($article, $event),
            'review.reopened' => [
                'subject' => 'Review Reopened: ' . $title,
                'body' => [
                    'A completed reviewer assignment has been reopened.',
                    'Next Action: The reviewer may update and resubmit the review. Monitor the reviewer desk for progress.',
                ],
            ],
            'revision.requested' => [
                'subject' => 'Revision Required: ' . $title . ' — ' . ($article->tracking_code ?? 'Not assigned'),
                'body' => [
                    'The editorial team has requested revisions for your article.',
                    'Revision Notes: ' . strip_tags((string) ($article->rejection_reason ?? 'Please review the author-visible comments in your workflow.')),
                    'Next Action: Please revise your article and submit the updated manuscript from your article workflow page. After resubmission, the system creates a revision tracking code ending in -R1.',
                ],
            ],
            'article.accepted' => [
                'subject' => 'Article Accepted: ' . $title . ' — ' . ($article->tracking_code ?? 'Not assigned'),
                'body' => [
                    '<strong>Congratulations—your manuscript has been accepted.</strong>',
                    'Decision Details: Decision: Accepted. Decision Date: ' . $this->eventTime() . '.',
                    'Next Action: The manuscript will proceed to author final review or production according to the editorial workflow.',
                ],
            ],
            'article.rejected' => [
                'subject' => 'Article Decision: ' . $title . ' — ' . ($article->tracking_code ?? 'Not assigned'),
                'body' => [
                    'The editorial review of your manuscript is complete.',
                    'Decision Details: Decision: Rejected. Decision Date: ' . $this->eventTime() . '.',
                    'Decision Notes: ' . strip_tags((string) ($article->rejection_reason ?? 'No author-visible decision notes were recorded.')),
                    'Next Action: This editorial decision is final unless the editorial team contacts you with further instructions.',
                    'Thank you for considering ScholarlyNest for your work.',
                ],
            ],
            'production.assigned' => [
                'subject' => 'Production Assignment: ' . $title,
                'body' => ['A production assignment has been created.', 'Next Action: Open the workflow to review the assigned production task and its files.'],
            ],
            'production.completed' => [
                'subject' => 'Production Task Completed: ' . $title,
                'body' => ['Copyediting has been completed.', 'Next Action: The corresponding author must approve or deny publication within 14 days.'],
            ],
            'author.final_review_requested' => [
                'subject' => 'Publication Approval Required: ' . $title,
                'body' => [
                    'Copyediting has been completed and the article is ready for your review.',
                    'You have 14 days to approve publication or return the article to copyediting with a reason.',
                    'If no response is received within 14 days, publication approval will be recorded automatically.',
                    'Next Action: Open the article workflow and review the copyedited manuscript.',
                ],
            ],
            'author.final_review_denied' => [
                'subject' => 'Publication Returned to Copyediting: ' . $title,
                'body' => [
                    'The author has denied publication of the current copyedited version.',
                    'Requested Changes: ' . strip_tags((string) ($event->payload['reason'] ?? 'No reason supplied.')),
                    'Next Action: Update the copyedited manuscript and complete the copyediting task again.',
                ],
            ],
            'author.final_review_approved' => [
                'subject' => 'Publication Approved by Author: ' . $title,
                'body' => ['The author approved the copyedited article for publication.', 'Next Action: Complete publication preparation.'],
            ],
            'author.final_review_auto_approved' => [
                'subject' => 'Publication Automatically Approved: ' . $title,
                'body' => ['The 14-day author response window expired without a response.', 'The article has been automatically approved and is ready for publication.'],
            ],
            'article.ready_for_publication' => [
                'subject' => 'Article Ready for Publication: ' . $title,
                'body' => ['The manuscript has completed its required preparation and is ready for publication.', 'Next Action: Verify the publication metadata and publish when authorized.'],
            ],
            'article.published' => [
                'subject' => 'Article Published: ' . $title,
                'body' => ['<strong>Your article has been published successfully.</strong>', 'Next Action: Open the article page to review the published record.'],
            ],
            'post_publication.recorded' => [
                'subject' => 'Post-Publication Action Recorded: ' . $title,
                'body' => ['A post-publication action has been recorded.', 'Next Action: Open the workflow to review the action, reason, and public notice.'],
            ],
            'article.resubmitted' => [
                'subject' => 'Article Resubmitted: ' . $title . ' — ' . $this->nextRevisionTrackingCode($article),
                'body' => [
                    ($article->user?->name ?? $article->user?->email ?? 'The submitting author') . ' submitted a revised version of the manuscript.',
                    'Revision Details: Base Tracking Code: ' . ($article->tracking_code ?? 'Not assigned') . '. Revision Tracking Code: ' . $this->nextRevisionTrackingCode($article) . '. Revision Number: ' . max(1, (int) $article->versions()->max('version_number')) . '. Submitted By: ' . ($article->user?->name ?? $article->user?->email ?? 'Not recorded') . '. Submitted At: ' . $this->eventTime() . '.',
                    'Change Summary: ' . strip_tags((string) ($article->change_summary ?? 'No change summary supplied.')),
                    'Next Action: Review the revised manuscript and continue the editorial workflow.',
                ],
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

    private function messageForRecipient($article, ArticleWorkflowEventOccurred $event, array $recipient): array
    {
        return match ($event->event) {
            'sub_editor.assigned' => $this->subEditorAssignmentMessage($article, $event, $recipient),
            'reviewer.assigned' => $this->reviewerAssignmentMessage($article, $event, $recipient),
            'production.assigned' => $this->productionAssignmentMessage($article, $event, $recipient),
            'article.accepted', 'article.rejected', 'article.published' => $this->articleOutcomeMessage($article, $event, $recipient),
            'article.resubmitted' => $this->resubmissionMessage($article, $event, $recipient),
            default => $this->messageFor($article, $event),
        };
    }

    private function subEditorAssignmentMessage($article, ArticleWorkflowEventOccurred $event, array $recipient): array
    {
        $subEditor = $event->payload['sub_editor'] ?? null;
        $subEditorName = $subEditor?->name ?? 'the assigned Sub Editor';
        $actorName = $event->actor?->name ?? 'Editorial Team';
        $details = [
            '<br><strong>Assignment Details:</strong>',
            '• <strong>Assigned By:</strong> ' . e($actorName),
            '• <strong>Sub Editor:</strong> ' . e($subEditorName),
            '• <strong>Publication:</strong> ' . e($article->magazine?->title ?? 'ScholarlyNest'),
            '• <strong>Article Tracking Number:</strong> ' . e($article->tracking_code ?? 'Not assigned'),
            '• <strong>Assigned At:</strong> ' . $this->eventTime(),
        ];

        if ($recipient['type'] === 'sub_editor') {
            return [
                'subject' => 'Sub Editor Assignment: ' . $article->title,
                'body' => array_merge([
                    'You have been assigned as the Sub Editor for the manuscript “' . e($article->title) . '”.',
                ], $details, [
                    'Next Action: Please review the manuscript and complete the required Sub Editor actions from your workflow desk.',
                ]),
            ];
        }

        if ($recipient['type'] === 'assigner') {
            return [
                'subject' => 'Sub Editor Assigned: ' . $article->title,
                'body' => array_merge([
                    e($subEditorName) . ' has been assigned as the Sub Editor for the manuscript “' . e($article->title) . '”.',
                ], $details, [
                    'Next Action: You can monitor the manuscript and the Sub Editor’s progress from the workflow workspace.',
                ]),
            ];
        }

        if (in_array($recipient['type'], ['article_owner', 'corresponding_author'], true)) {
            return [
                'subject' => 'Editorial Update: Sub Editor Assigned to Your Manuscript',
                'body' => [
                    'A Sub Editor has been assigned to your manuscript “' . e($article->title) . '”.',
                    '<br><strong>Editorial Update:</strong>',
                    '• <strong>Sub Editor:</strong> ' . e($subEditorName),
                    '• <strong>Publication:</strong> ' . e($article->magazine?->title ?? 'ScholarlyNest'),
                    '• <strong>Article Tracking Number:</strong> ' . e($article->tracking_code ?? 'Not assigned'),
                    '• <strong>Updated At:</strong> ' . $this->eventTime(),
                    'Next Action: Your manuscript is continuing through editorial review. No action is required unless the editorial team contacts you.',
                ],
            ];
        }

        return [
            'subject' => 'Workflow Update: Sub Editor Assigned',
            'body' => array_merge([
                e($subEditorName) . ' has been assigned as the Sub Editor for the manuscript “' . e($article->title) . '”.',
            ], $details, [
                'Next Action: This notification is for workflow oversight. No action is required unless administrative intervention is needed.',
            ]),
        ];
    }

    private function reviewerAssignmentMessage($article, ArticleWorkflowEventOccurred $event, array $recipient): array
    {
        $reviewer = $event->payload['reviewer'] ?? null;
        $reviewerName = $reviewer?->name ?? ($event->payload['invitee_name'] ?? 'Invited reviewer');
        $actorName = $event->actor?->name ?? 'Editorial Team';

        if ($recipient['type'] === 'reviewer') {
            return [
                'subject' => 'Reviewer Invitation: ' . $article->title,
                'body' => [
                    'You have been invited to review the manuscript “' . e($article->title) . '”.',
                    'Invited By: ' . e($actorName) . '.',
                    'Publication: ' . e($article->magazine?->title ?? 'ScholarlyNest') . '.',
                    'Article Tracking Number: ' . e($article->tracking_code ?? 'Not assigned') . '.',
                    'Invited At: ' . $this->eventTime() . '.',
                    'Next Action: Open your review assignment to accept or decline the invitation.',
                    'Privacy Note: Only manuscript information permitted by the review policy will be available to you.',
                ],
            ];
        }

        if (in_array($recipient['type'], ['article_owner', 'corresponding_author'], true)) {
            return [
                'subject' => 'Editorial Update: Reviewer Invited for Your Manuscript',
                'body' => [
                    'A reviewer has been invited for your manuscript “' . e($article->title) . '”.',
                    'Your manuscript is continuing through peer review.',
                    'Next Action: No action is required from you at this stage.',
                    'Privacy Note: Reviewer identity is not disclosed under the blind-review policy.',
                ],
            ];
        }

        return [
            'subject' => 'Reviewer Invitation Sent: ' . $article->title,
            'body' => [
                e($reviewerName) . ' was invited to review the manuscript “' . e($article->title) . '”.',
                'Invited By: ' . e($actorName) . '.',
                'Invited At: ' . $this->eventTime() . '.',
                'Next Action: Monitor the invitation response from the article workflow.',
            ],
        ];
    }

    private function productionAssignmentMessage($article, ArticleWorkflowEventOccurred $event, array $recipient): array
    {
        $assigneeName = ($event->payload['assignee'] ?? null)?->name ?? 'the assigned Copy Editor';
        if ($recipient['type'] === 'production_assignee') {
            return [
                'subject' => 'Copy-Editing Assignment: ' . $article->title,
                'body' => [
                    'You have been assigned to copy-edit the manuscript “' . e($article->title) . '”.',
                    'Assigned By: ' . e($event->actor?->name ?? 'Editorial Team') . '.',
                    'Assigned At: ' . $this->eventTime() . '.',
                    'Next Action: Open the Copy-Editing workspace and work from the Accepted Files package.',
                ],
            ];
        }

        $authorRecipient = in_array($recipient['type'], ['article_owner', 'corresponding_author'], true);
        return [
            'subject' => $authorRecipient ? 'Production Update: Copy Editing Started' : 'Copy Editor Assigned: ' . $article->title,
            'body' => [
                ($authorRecipient ? 'A Copy Editor has been assigned to your manuscript.' : e($assigneeName) . ' has been assigned as Copy Editor for this manuscript.'),
                'Next Action: ' . ($authorRecipient ? 'No action is required while copy editing is in progress.' : 'Monitor copy-editing progress from the article workflow.'),
            ],
        ];
    }

    private function articleOutcomeMessage($article, ArticleWorkflowEventOccurred $event, array $recipient): array
    {
        $isAuthor = in_array($recipient['type'], ['article_owner', 'corresponding_author'], true);
        if ($isAuthor) {
            return $this->messageFor($article, $event);
        }

        $label = match ($event->event) {
            'article.accepted' => 'accepted',
            'article.rejected' => 'rejected',
            default => 'published',
        };

        return [
            'subject' => 'Workflow Update: Article ' . ucfirst($label) . ' — ' . $article->title,
            'body' => [
                'The manuscript “' . e($article->title) . '” has been ' . $label . '.',
                'Updated By: ' . e($event->actor?->name ?? 'System workflow') . '.',
                'Updated At: ' . $this->eventTime() . '.',
                'Next Action: Open the article workflow to review the current record and any action available to your role.',
            ],
        ];
    }

    private function resubmissionMessage($article, ArticleWorkflowEventOccurred $event, array $recipient): array
    {
        if (in_array($recipient['type'], ['article_owner', 'corresponding_author'], true)) {
            return [
                'subject' => 'Your Revised Manuscript Has Been Received',
                'body' => [
                    'Your revised manuscript “' . e($article->title) . '” has been received successfully.',
                    'Revision: ' . e($this->nextRevisionTrackingCode($article)) . '.',
                    'Received At: ' . $this->eventTime() . '.',
                    'Next Action: No action is required while the editorial team reviews your revision.',
                ],
            ];
        }

        return $this->messageFor($article, $event);
    }

    private function nextRevisionTrackingCode($article): string { return ($article->tracking_code ?? 'Not assigned') . '-R' . max(1, (int) $article->versions()->max('version_number')); }

    private function subEditorRecipients($article): Collection { return collect($article->subEditorAssignments()->with('subEditor')->get()->map(fn ($a) => $this->userRecipient($a->subEditor, 'sub_editor'))->all()); }

    private function reviewerResponseMessage($article, ArticleWorkflowEventOccurred $event): array
    {
        $reviewer = $event->actor;
        $accepted = $event->event === 'review.accepted';
        $name = $reviewer?->name ?? ($event->payload['reviewer_name'] ?? 'Reviewer');
        $email = $reviewer?->email ?? ($event->payload['reviewer_email'] ?? 'Email unavailable');

        return [
            'subject' => 'Reviewer ' . ($accepted ? 'Accepted' : 'Declined') . ' Invitation: ' . $name . ' — ' . $article->title,
            'body' => [
                'The reviewer has <strong>' . ($accepted ? 'accepted' : 'declined') . '</strong> the invitation.',
                'Reviewer Response: Reviewer Name: ' . $name . '. Reviewer Email: ' . $email . '. Response: ' . ($accepted ? 'Accepted' : 'Declined') . '. Responded At: ' . $this->eventTime() . '.',
                'Next Action: ' . ($accepted
                    ? 'The reviewer can now access the permitted manuscript files and submit a recommendation from the reviewer dashboard.'
                    : 'Assign another reviewer or continue according to the editorial review policy.'),
            ],
        ];
    }

    private function reviewSubmittedMessage($article, ArticleWorkflowEventOccurred $event): array
    {
        $reviewer = $event->actor;

        return [
            'subject' => 'Review Submitted: ' . ($reviewer?->name ?? 'Reviewer') . ' — ' . $article->title,
            'body' => [
                'A reviewer has completed and submitted a manuscript evaluation.',
                'Review Details: Reviewer Name: ' . ($reviewer?->name ?? 'Reviewer') . '. Reviewer Email: ' . ($reviewer?->email ?? 'Email unavailable') . '. Recommendation: ' . str_replace('_', ' ', (string) ($event->payload['recommendation'] ?? 'Not recorded')) . '. Submitted At: ' . $this->eventTime() . '.',
                'Next Action: Review the recommendation, questionnaire responses, and comments before continuing the editorial decision process.',
                'Privacy Note: Reviewer comments, identity, and confidential recommendations are available only to authorized editorial users.',
            ],
        ];
    }

    private function transferRequestedMessage($article, ArticleWorkflowEventOccurred $event): array
    {
        $fromMagazine = $this->magazineTitle($event->payload['from_magazine_id'] ?? null, $article->magazine?->title);
        $toMagazine = $this->magazineTitle($event->payload['to_magazine_id'] ?? null, $event->payload['target_magazine'] ?? 'the suggested magazine');

        return [
            'subject' => 'Magazine Transfer Request: ' . $article->title,
            'body' => [
                'The editor of <strong>' . htmlspecialchars($fromMagazine) . '</strong> has initiated a request to transfer your manuscript to <strong>' . htmlspecialchars($toMagazine) . '</strong> because it is more suitable for its focus and scope.',
                '<br><strong>Transfer Details:</strong>',
                '• <strong>Manuscript Title:</strong> ' . htmlspecialchars($article->title),
                '• <strong>Tracking Code:</strong> ' . htmlspecialchars($article->tracking_code ?? 'Not assigned'),
                '• <strong>Current Magazine:</strong> ' . htmlspecialchars($fromMagazine),
                '• <strong>Suggested Magazine:</strong> ' . htmlspecialchars($toMagazine),
                '• <strong>Requested By:</strong> ' . htmlspecialchars($event->actor?->name ?? 'Editorial team'),
                '• <strong>Requested At:</strong> ' . ($event->payload['requested_at'] ?? $this->eventTime()),
                '<br><strong>Editor Comments & Rationale:</strong>',
                '<div style="background-color: #f8fafc; border-left: 4px solid #3b82f6; padding: 12px 16px; margin: 8px 0; font-style: italic; color: #475569;">' . nl2br(htmlspecialchars(strip_tags((string) ($event->payload['editor_comments'] ?? 'No rationale comments provided.')))) . '</div>',
                '<br>Please log in to your ScholarlyNest account to review, accept, or reject this transfer request.',
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
                'The author has <strong>accepted</strong> the magazine transfer request.',
                '<br><strong>Transfer Details:</strong>',
                '• <strong>Manuscript Title:</strong> ' . htmlspecialchars($article->title),
                '• <strong>Tracking Code:</strong> ' . htmlspecialchars($article->tracking_code ?? 'Not assigned'),
                '• <strong>Original Magazine:</strong> ' . htmlspecialchars($fromMagazine),
                '• <strong>New Magazine:</strong> ' . htmlspecialchars($toMagazine),
                '• <strong>Accepted By:</strong> ' . htmlspecialchars($event->actor?->name ?? 'Author'),
                '• <strong>Accepted At:</strong> ' . $this->eventTime(),
                '<br><strong>Next Action:</strong> The article has been moved to the new magazine and is now in <strong>Screening</strong> stage.',
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
                'The author has <strong>rejected</strong> the magazine transfer request.',
                '<br><strong>Transfer Details:</strong>',
                '• <strong>Manuscript Title:</strong> ' . htmlspecialchars($article->title),
                '• <strong>Tracking Code:</strong> ' . htmlspecialchars($article->tracking_code ?? 'Not assigned'),
                '• <strong>Original Magazine:</strong> ' . htmlspecialchars($fromMagazine),
                '• <strong>Suggested Magazine:</strong> ' . htmlspecialchars($toMagazine),
                '• <strong>Rejected By:</strong> ' . htmlspecialchars($event->actor?->name ?? 'Author'),
                '• <strong>Rejected At:</strong> ' . $this->eventTime(),
                '<br><strong>Author Rejection Reason:</strong>',
                '<div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 12px 16px; margin: 8px 0; font-style: italic; color: #991b1b;">' . nl2br(htmlspecialchars(strip_tags((string) ($event->payload['author_rejection_reason'] ?? 'No reason provided.')))) . '</div>',
                '<br><strong>Next Action:</strong> The article remains in the original magazine under the <strong>Screening</strong> stage.',
            ],
        ];
    }

    private function workflowContextLines($article, ArticleWorkflowEventOccurred $event): array
    {
        $status = ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::normalize($article->status)] ?? str_replace('_', ' ', $article->status);
        $actor = $event->actor?->name ?? 'System workflow';
        return [
            '<br><strong>Manuscript Details:</strong>',
            '• <strong>Title:</strong> ' . htmlspecialchars($article->title),
            '• <strong>Magazine:</strong> ' . htmlspecialchars($article->magazine?->title ?? 'ScholarlyNest'),
            '• <strong>Tracking Code:</strong> ' . htmlspecialchars($article->tracking_code ?? 'Not assigned'),
            '• <strong>Current Status:</strong> ' . htmlspecialchars(ucwords(str_replace('_', ' ', $status))),
            '• <strong>Updated By:</strong> ' . htmlspecialchars($actor),
            '• <strong>Updated At:</strong> ' . $this->eventTime(),
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
                        $pivotQuery->whereIn('magazine_user.role', ['editor'])
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

    private function copyEditorRecipients($article): Collection
    {
        return ProductionAssignment::query()
            ->with('user')
            ->where('article_id', $article->id)
            ->where('role', 'copy_editor')
            ->get()
            ->map(fn (ProductionAssignment $assignment) => $this->userRecipient($assignment->user, 'copy_editor'))
            ->pipe(fn ($items) => $this->dedupe($items));
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
        if ($user?->id && !$user->email) {
            $user = User::find($user->id);
        }
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

    private function actionForRecipient($article, array $recipient): array
    {
        $text = match ($recipient['type']) {
            'sub_editor' => 'Open Sub Editor Workflow',
            'reviewer' => 'Open Review Assignment',
            'production_assignee', 'copy_editor' => 'Open Copy-Editing Workspace',
            'publisher' => 'Open Production Workspace',
            'article_owner', 'corresponding_author' => 'View Submission Status',
            default => 'Open Article Workflow',
        };

        return [
            'text' => $text,
            'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . '/admin/articles/' . $article->id . '/workflow',
        ];
    }

    private function recipientFallbackName(string $type): string
    {
        return match ($type) {
            'article_owner', 'corresponding_author' => 'Author',
            'reviewer' => 'Reviewer',
            'sub_editor' => 'Sub Editor',
            'production_assignee', 'copy_editor' => 'Copy Editor',
            default => 'Editorial Team',
        };
    }

    private function eventTime(): string
    {
        return now()->format('d-M-Y H:i');
    }
}
