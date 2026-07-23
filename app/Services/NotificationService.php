<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use Illuminate\Database\QueryException;
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
        ?string $unsubscribeUrl = null,
        array $context = [],
    ): bool {
        $attributes = [
            'notification_event_id' => $context['notification_event_id'] ?? null,
            'user_notification_id' => $context['user_notification_id'] ?? null,
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
            'channel' => 'email',
            'purpose' => $context['purpose'] ?? null,
            'deduplication_key' => $context['deduplication_key'] ?? null,
            'privacy_variant' => $context['privacy_variant'] ?? null,
            'status' => 'queued',
            'retry_count' => 0,
            'queued_at' => now(),
        ];

        try {
            $log = NotificationLog::create($attributes);
        } catch (QueryException $exception) {
            if (! ($context['deduplication_key'] ?? null)) {
                throw $exception;
            }
            $log = NotificationLog::where('deduplication_key', $context['deduplication_key'])->firstOrFail();

            return false;
        }

        SendNotificationJob::dispatch($log->id)->afterCommit()->onQueue($queue);

        return true;
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
        $encryptedPayload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $log = NotificationLog::create([
            'user_id' => $userId,
            'recipient_email' => $email,
            'subject' => $subject,
            'payload' => ['encrypted' => $encryptedPayload],
            'channel' => 'email',
            'purpose' => 'sensitive',
            'status' => 'queued',
            'retry_count' => 0,
            'queued_at' => now(),
        ]);
        SendNotificationJob::dispatch($log->id, $encryptedPayload)->afterCommit()->onQueue($queue);
    }
}
