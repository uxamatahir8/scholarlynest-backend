<?php

namespace App\Listeners;

use App\Events\ArticleSubmitted;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

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
        $article->load('user');
        $primaryAuthor = $article->user;
        $coAuthorsData = $event->coAuthorsData;

        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/');

        // 1. Send to the Primary Author
        if ($primaryAuthor) {
            $trackingToken = 'SN-' . $article->id . '-' . strtoupper(substr($article->slug, -6));
            $subject = 'Manuscript Submitted Successfully';
            $greeting = 'Dear ' . $primaryAuthor->name . ',';
            $bodyLines = [
                'We are pleased to confirm that your manuscript titled "' . $article->title . '" has been successfully submitted to ScholarlyNest.',
                'Submission Reference Token: <strong>' . $trackingToken . '</strong>',
                'Your manuscript is currently in "pending" status and will proceed through the editorial and peer-review workflows. You can monitor the progress of your submission directly from your Author Dashboard.',
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
            }
        }
    }
}
