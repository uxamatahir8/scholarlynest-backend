<?php

namespace App\Jobs;

use App\Mail\GenericSystemMail;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The database log ID associated with this dispatch.
     */
    public int $logId;

    public ?string $encryptedSensitivePayload;

    /**
     * Create a new job instance.
     */
    public function __construct(int $logId, ?string $encryptedSensitivePayload = null)
    {
        $this->logId = $logId;
        $this->encryptedSensitivePayload = $encryptedSensitivePayload;
    }

    /**
     * Get the backoff times (in seconds) for retry attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 120, 240, 480, 960];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $log = NotificationLog::findOrFail($this->logId);

        try {
            $payload = $log->payload;
            if ($log->status === 'sent' && isset($payload['redacted'])) {
                return;
            }
            if (isset($payload['redacted']) && ! $this->encryptedSensitivePayload) {
                $this->skipEmptySensitiveNotification($log);

                return;
            }

            $sensitive = isset($payload['encrypted']) || $this->encryptedSensitivePayload;
            if ($sensitive) {
                $encryptedPayload = $this->encryptedSensitivePayload ?? $payload['encrypted'];
                $payload = json_decode(Crypt::decryptString($encryptedPayload), true, flags: JSON_THROW_ON_ERROR);
                if (empty($payload['bodyLines']) || ! is_array($payload['bodyLines'])) {
                    $this->skipEmptySensitiveNotification($log);

                    return;
                }
            }
            $action = $payload['action'] ?? null;
            $replyToEmail = $payload['reply_to_email'] ?? null;
            $replyToName = $payload['reply_to_name'] ?? null;
            $unsubscribeUrl = $payload['unsubscribe_url'] ?? null;

            $mailable = new GenericSystemMail(
                $log->recipient_email,
                $log->subject,
                $payload['greeting'] ?? 'Hello,',
                $payload['bodyLines'] ?? [],
                $action,
                $replyToEmail,
                $replyToName,
                $unsubscribeUrl
            );

            Mail::to($log->recipient_email)->send($mailable);

            $log->update([
                'status' => 'sent',
                'error_message' => null,
                ...($sensitive ? ['payload' => ['redacted' => true]] : []),
            ]);
        } catch (Throwable $exception) {
            $log->increment('retry_count');
            $log->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage()."\n".$exception->getTraceAsString(),
            ]);
            throw $exception;
        }
    }

    private function skipEmptySensitiveNotification(NotificationLog $log): void
    {
        $log->update([
            'status' => 'failed',
            'error_message' => 'Sensitive notification payload unavailable; email was not sent.',
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $log = NotificationLog::find($this->logId);
        if ($log) {
            $log->update([
                'status' => 'failed',
                'error_message' => 'Job Failed after max retries: '.$exception->getMessage()."\n".$exception->getTraceAsString(),
            ]);
        }
    }
}
