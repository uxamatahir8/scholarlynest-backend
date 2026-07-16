<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationJob;
use App\Mail\GenericSystemMail;
use App\Models\NotificationLog;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SensitiveNotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_two_factor_email_sends_body_and_code_before_redacting_payload(): void
    {
        Queue::fake();
        Mail::fake();

        app(NotificationService::class)->sendSensitive(
            'info@scholarlynest.com',
            'Your Two-Factor Authentication Code',
            'Two-Factor Authentication',
            [
                'A sign-in attempt was detected for your Scholarly Nest profile.',
                '<div class="code-box"><div class="code-value">123456</div></div>',
            ],
        );

        $log = NotificationLog::firstOrFail();
        $encryptedPayload = $log->payload['encrypted'];
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->encryptedSensitivePayload === $encryptedPayload);

        (new SendNotificationJob($log->id, $encryptedPayload))->handle();

        Mail::assertSent(GenericSystemMail::class, function (GenericSystemMail $mail) {
            return $mail->subject === 'Your Two-Factor Authentication Code'
                && $mail->greeting === 'Two-Factor Authentication'
                && in_array('A sign-in attempt was detected for your Scholarly Nest profile.', $mail->bodyLines, true)
                && str_contains(implode("\n", $mail->bodyLines), '123456');
        });

        $this->assertSame('sent', $log->refresh()->status);
        $this->assertSame(['redacted' => true], $log->payload);
    }

    public function test_sensitive_job_snapshot_prevents_redacted_log_from_sending_blank_email(): void
    {
        Queue::fake();
        Mail::fake();

        app(NotificationService::class)->sendSensitive(
            'info@scholarlynest.com',
            'Your Two-Factor Authentication Code',
            'Two-Factor Authentication',
            [
                'A sign-in attempt was detected for your Scholarly Nest profile.',
                '<div class="code-box"><div class="code-value">654321</div></div>',
            ],
        );

        $log = NotificationLog::firstOrFail();
        $encryptedPayload = $log->payload['encrypted'];
        $log->update(['payload' => ['redacted' => true]]);

        (new SendNotificationJob($log->id, $encryptedPayload))->handle();

        Mail::assertSent(GenericSystemMail::class, function (GenericSystemMail $mail) {
            return $mail->greeting === 'Two-Factor Authentication'
                && str_contains(implode("\n", $mail->bodyLines), '654321');
        });

        $this->assertSame('sent', $log->refresh()->status);
        $this->assertSame(['redacted' => true], $log->payload);
    }

    public function test_redacted_sensitive_payload_is_not_sent_as_blank_email(): void
    {
        Mail::fake();

        $log = NotificationLog::create([
            'recipient_email' => 'info@scholarlynest.com',
            'subject' => 'Your Two-Factor Authentication Code',
            'payload' => ['redacted' => true],
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        (new SendNotificationJob($log->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame('failed', $log->refresh()->status);
        $this->assertSame('Sensitive notification payload unavailable; email was not sent.', $log->error_message);
    }
}
