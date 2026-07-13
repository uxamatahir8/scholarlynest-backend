<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Crypt;

class NotificationService
{
    /**
     * Dispatch an email asynchronously with full database logging.
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

    /**
     * Queue an email whose body contains a secret. The queue payload is encrypted
     * at rest and is erased after delivery by SendNotificationJob.
     */
    public function sendSensitive(
        string $email,
        string $subject,
        string $greeting,
        array $bodyLines,
        ?array $action = null,
        string $queue = 'default',
        ?int $userId = null,
    ): void {
        $payload = compact('greeting', 'bodyLines', 'action');
        $log = NotificationLog::create([
            'user_id' => $userId,
            'recipient_email' => $email,
            'subject' => $subject,
            'payload' => ['encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))],
            'status' => 'pending',
            'retry_count' => 0,
        ]);
        SendNotificationJob::dispatch($log->id)->onQueue($queue);
    }
}
