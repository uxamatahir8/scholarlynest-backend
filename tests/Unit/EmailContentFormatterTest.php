<?php

namespace Tests\Unit;

use App\Mail\GenericSystemMail;
use App\Mail\WelcomeNewsletterMail;
use App\Models\NewsletterSubscriber;
use App\Services\EmailContentFormatter;
use Tests\TestCase;

class EmailContentFormatterTest extends TestCase
{
    public function test_it_formats_dense_notification_content_into_readable_blocks(): void
    {
        $blocks = app(EmailContentFormatter::class)->format([
            'Your submission was received.',
            'Article Details: Article Title: A useful study. Magazine: Research Review. Tracking Code: SN-2026-000001.',
            'Next Action: Open the workflow and review the submission.',
            '<br><strong>Transfer Details:</strong>',
            '• <strong>Current Magazine:</strong> Animal Research',
        ]);

        $this->assertSame(['paragraph', 'details', 'callout', 'heading', 'list_item'], array_column($blocks, 'type'));
        $this->assertSame('Article Details', $blocks[1]['title']);
        $this->assertSame(['Article Title', 'Magazine', 'Tracking Code'], array_column($blocks[1]['rows'], 'label'));
        $this->assertSame('Next Action', $blocks[2]['title']);
        $this->assertSame('Current Magazine', $blocks[4]['label']);
    }

    public function test_generic_mail_renders_labels_code_spacing_and_emphasis(): void
    {
        $mail = new GenericSystemMail(
            'reader@example.test',
            'Email formatting test',
            'Dear Reader,',
            [
                'Account Details: Name: Reader Name. Email: reader@example.test. Role: Reviewer.',
                '<div class="code-box"><div class="code-value">123456</div></div>',
                'Next Action: Enter the code to continue.',
            ],
            ['text' => 'Continue', 'url' => 'https://example.test/continue']
        );

        $html = $mail->render();

        $this->assertStringContainsString('class="details-card"', $html);
        $this->assertStringContainsString('class="detail-label">Name', $html);
        $this->assertStringContainsString('class="code-value">123456', $html);
        $this->assertStringContainsString('<strong>Next Action</strong>', $html);
        $this->assertStringContainsString('>Continue</a>', $html);
    }

    public function test_newsletter_welcome_uses_shared_template_and_unsubscribe_link(): void
    {
        $subscriber = new NewsletterSubscriber([
            'email' => 'subscriber@example.test',
            'token' => 'unsubscribe-token',
        ]);

        $html = (new WelcomeNewsletterMail($subscriber))->render();

        $this->assertStringContainsString('Subscription confirmed', $html);
        $this->assertStringContainsString('What You Will Receive', $html);
        $this->assertStringContainsString('/unsubscribe/unsubscribe-token', $html);
    }
}
