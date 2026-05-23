<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;

class NotificationService
{
    /**
     * Dispatch an email asynchronously with full database logging.
     *
     * @param string $email
     * @param string $subject
     * @param string $greeting
     * @param array $bodyLines
     * @param array|null $action
     * @param string $queue
     * @param int|null $userId
     * @param string|null $replyToEmail
     * @param string|null $replyToName
     * @param string|null $unsubscribeUrl
     * @return void
     */
    public function send(
        string $email,
        string $subject,
        string $greeting,
        array $bodyLines,
        ?array $action = null,
        string $queue = 'default',
        ?int $userId = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        ?string $unsubscribeUrl = null
    ): void {
        $log = NotificationLog::create([
            'user_id' => $userId,
            'recipient_email' => $email,
            'subject' => $subject,
            'payload' => [
                'greeting' => $greeting,
                'bodyLines' => $bodyLines,
                'action' => $action,
                'reply_to_email' => $replyToEmail,
                'reply_to_name' => $replyToName,
                'unsubscribe_url' => $unsubscribeUrl,
            ],
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        SendNotificationJob::dispatch($log->id)->onQueue($queue);
    }
}
