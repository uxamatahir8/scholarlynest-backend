<?php

namespace App\Listeners;

use App\Events\ArticleSubmitted;
use App\Models\ArticleAuditLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendArticleSubmissionNotifications implements ShouldQueue
{
    protected NotificationService $notificationService;

    /**
     * Create the event listener.
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
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
            $trackingToken = 'SN-' . $article->id . '-' . strtoupper(substr($article->slug, -6));
            $subject = 'Manuscript Submitted Successfully';
            $greeting = 'Dear ' . $primaryAuthor->name . ',';
            $bodyLines = [
                'We are pleased to confirm that your manuscript titled "' . $article->title . '" has been successfully submitted to ScholarlyNest.',
                'Submission Reference Token: <strong>' . $trackingToken . '</strong>',
                'Your manuscript is currently in "submitted" status and will proceed through the editorial and peer-review workflows. You can monitor the progress of your submission directly from your Author Dashboard.',
                'Thank you for publishing your research with ScholarlyNest.'
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
            $hasTempPassword = !empty($coAuthor['temporary_password']);

            if ($hasTempPassword) {
                // Account was generated
                $subject = 'Onboarding Welcome: Co-Author Invitation';
                $greeting = 'Dear ' . $name . ',';
                $bodyLines = [
                    'You have been designated as a co-author on the newly submitted article: "' . $article->title . '".',
                    'An official author profile has been automatically generated for you on ScholarlyNest.',
                    'Your temporary credentials are:',
                    'Login Email: ' . $email,
                    'Temporary Password: ' . $coAuthor['temporary_password'],
                    'Please log in and update your password immediately to secure your account.'
                ];
                $action = [
                    'text' => 'Reset Password',
                    'url' => $frontendUrl . '/reset-password',
                ];

                $this->notificationService->send(
                    $email,
                    $subject,
                    $greeting,
                    $bodyLines,
                    $action,
                    'high',
                    $coAuthor['user_id'] ?? null
                );
                $recipientCount++;
            } else {
                // Text-only informational notification email (for existing account or create_account = false)
                $subject = 'Notification: Co-Author Designation';
                $greeting = 'Dear ' . $name . ',';
                $bodyLines = [
                    'This email is to notify you that you have been listed as an official co-author on the article manuscript titled "' . $article->title . '", which has been successfully submitted for editorial review.',
                    'If you already possess a ScholarlyNest account, you can access the article details through your Dashboard.'
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
                'New Manuscript Submitted: ' . $article->title,
                $recipient['name'] ? 'Dear ' . $recipient['name'] . ',' : 'Hello,',
                [
                    'A new manuscript titled "' . $article->title . '" has been submitted to ' . ($article->magazine?->title ?? 'ScholarlyNest') . '.',
                    'Please open the admin article board to begin editorial screening and assignment.',
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
