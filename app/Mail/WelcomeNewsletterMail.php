<?php

namespace App\Mail;

use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeNewsletterMail extends Mailable
{
    use Queueable, SerializesModels;

    public NewsletterSubscriber $subscriber;

    /**
     * Create a new message instance.
     */
    public function __construct(NewsletterSubscriber $subscriber)
    {
        $this->subscriber = $subscriber;
    }

    /**
     * Build the message.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to ScholarlyNest!');
    }

    public function content(): Content
    {
        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');
        $bodyLines = [
            'Thank you for subscribing to the ScholarlyNest newsletter. We are delighted to welcome you to our global research community.',
            'What You Will Receive:',
            '• Monthly highlights from our academic magazines',
            '• Trending research articles and scholarly discoveries',
            '• Important platform news and publishing updates',
            'If you have questions or feedback, our team would be pleased to hear from you.',
            'Best regards,<br><strong>The ScholarlyNest Team</strong>',
        ];

        return new Content(view: 'emails.generic', with: [
            'subject' => $this->envelope()->subject,
            'greeting' => 'Subscription confirmed',
            'bodyLines' => $bodyLines,
            'bodyBlocks' => app(\App\Services\EmailContentFormatter::class)->format($bodyLines),
            'action' => null,
            'unsubscribeUrl' => "{$frontendUrl}/unsubscribe/{$this->subscriber->token}",
        ]);
    }
}
