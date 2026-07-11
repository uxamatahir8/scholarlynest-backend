<?php

namespace App\Listeners;

use App\Events\ArticleSubmitted;
use App\Models\ArticleAuditLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PasswordSetupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendArticleSubmissionNotifications implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected NotificationService $notificationService,
        protected PasswordSetupService $passwordSetupService
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(ArticleSubmitted $event): void
    {
        $article = $event->article;
        $article->load(['user', 'magazine']);
        $primaryAuthor = $article->user;
        $coAuthorsData = $event->coAuthorsData;

        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/');
        $recipientCount = 0;

        // 1. Send to the Primary Author
        if ($primaryAuthor) {
            $subject = 'Submission Received: ' . $article->title . ' — ' . ($article->magazine?->title ?? 'ScholarlyNest');
            $greeting = 'Dear ' . $primaryAuthor->name . ',';
            $bodyLines = [
                'Your article has been submitted successfully to ' . ($article->magazine?->title ?? 'ScholarlyNest') . '.',
                '<br><strong>Submission Details:</strong>',
                '• <strong>Article Title:</strong> ' . e($article->title),
                '• <strong>Magazine:</strong> ' . e($article->magazine?->title ?? 'ScholarlyNest'),
                '• <strong>Tracking Code:</strong> ' . e($article->tracking_code ?? 'Not assigned'),
                '• <strong>Submission Status:</strong> Submitted',
                '• <strong>Submitted At:</strong> ' . optional($article->created_at)->format('F j, Y g:i A T'),
                '<br><strong>Abstract:</strong>',
                '<div>' . nl2br(e(strip_tags((string) ($article->abstract ?? 'Not provided.')))) . '</div>',
                'Next Action: No immediate action is required. The editorial team will screen your submission, and you can track progress from your article dashboard.'
            ];
            $action = [
                'text' => 'Go to Dashboard',
                'url' => $frontendUrl . '/admin/articles',
            ];

            $this->notificationService->send(
                $primaryAuthor->email,
                $subject,
                $greeting,
                $bodyLines,
                $action,
                'default',
                $primaryAuthor->id
            );
            $recipientCount++;
        }

        // 2. Send to Co-Authors
        foreach ($coAuthorsData as $coAuthor) {
            $email = $coAuthor['email'];
            $name = $coAuthor['name'];
            $createdAccount = !empty($coAuthor['account_provisioned']) && !empty($coAuthor['user_id']);

            if ($createdAccount && ($createdUser = User::find($coAuthor['user_id']))) {
                $this->passwordSetupService->sendSetupLink($createdUser);
                $recipientCount++;
            } else {
                // Text-only informational notification email (for existing account or create_account = false)
                $subject = 'You Were Added as a Co-author: ' . $article->title;
                $greeting = 'Dear ' . $name . ',';
                $bodyLines = [
                    'You have been listed as a co-author on a submitted article in Scholarly Nest.',
                    '<br><strong>Submission Details:</strong>',
                    '• <strong>Article Title:</strong> ' . e($article->title),
                    '• <strong>Magazine:</strong> ' . e($article->magazine?->title ?? 'ScholarlyNest'),
                    '• <strong>Tracking Code:</strong> ' . e($article->tracking_code ?? 'Not assigned'),
                    '• <strong>Submitted At:</strong> ' . optional($article->created_at)->format('F j, Y g:i A T'),
                    '<br><strong>Abstract:</strong>',
                    '<div>' . nl2br(e(strip_tags((string) ($article->abstract ?? 'Not provided.')))) . '</div>',
                    'Next Action: Review the article status from your ScholarlyNest account. If you need a new account, a separate secure password setup email will be sent.'
                ];

                $this->notificationService->send(
                    $email,
                    $subject,
                    $greeting,
                    $bodyLines,
                    null,
                    'default',
                    $coAuthor['user_id'] ?? null
                );
                $recipientCount++;
            }
        }

        $staffRecipients = $this->staffRecipients($article);
        foreach ($staffRecipients as $recipient) {
            $this->notificationService->send(
                $recipient['email'],
                'New Article Submitted: ' . $article->title . ' — ' . ($article->tracking_code ?? 'Not assigned'),
                $recipient['name'] ? 'Dear ' . $recipient['name'] . ',' : 'Hello,',
                [
                    'A new manuscript titled "' . $article->title . '" has been submitted to ' . ($article->magazine?->title ?? 'ScholarlyNest') . '.',
                    '<br><strong>Submission Details:</strong>',
                    '• <strong>Tracking Code:</strong> ' . e($article->tracking_code ?? 'Not assigned'),
                    '• <strong>Submitting Author:</strong> ' . e($primaryAuthor?->name ?? 'Not recorded'),
                    '• <strong>Submitted At:</strong> ' . optional($article->created_at)->format('F j, Y g:i A T'),
                    '<br><strong>Abstract:</strong>',
                    '<div>' . nl2br(e(strip_tags((string) ($article->abstract ?? 'Not provided.')))) . '</div>',
                    'Next Action: Open the article board to begin editorial screening and assignment.',
                ],
                [
                    'text' => 'Open Article Board',
                    'url' => $frontendUrl . '/admin/articles',
                ],
                'default',
                $recipient['user_id'] ?? null
            );
            $recipientCount++;
        }

        ArticleAuditLog::create([
            'article_id' => $article->id,
            'actor_id' => $primaryAuthor?->id,
            'event' => 'notification.sent',
            'from_status' => $article->status,
            'to_status' => $article->status,
            'payload' => [
                'workflow_event' => 'article.submitted',
                'recipient_count' => $recipientCount,
                'recipient_types' => ['article_owner', 'corresponding_author', 'editor', 'super_admin'],
            ],
        ]);
    }

    private function staffRecipients($article): Collection
    {
        $editors = collect(User::query()
            ->whereHas('magazines', function ($query) use ($article) {
                $query->where('magazines.id', $article->magazine_id)
                    ->where(function ($pivotQuery) {
                        $pivotQuery->whereIn('magazine_user.role', ['editor', 'magazine_editor'])
                            ->orWhereNull('magazine_user.role');
                    });
            })
            ->get()
            ->map(fn (User $user) => [
                'email' => $user->email,
                'name' => $user->name,
                'user_id' => $user->id,
            ])
            ->all());

        $superAdmins = collect(User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'super_admin'))
            ->get()
            ->map(fn (User $user) => [
                'email' => $user->email,
                'name' => $user->name,
                'user_id' => $user->id,
            ])
            ->all());

        return $editors
            ->merge($superAdmins)
            ->filter(fn ($recipient) => !empty($recipient['email']))
            ->unique(fn ($recipient) => strtolower($recipient['email']))
            ->values();
    }
}
