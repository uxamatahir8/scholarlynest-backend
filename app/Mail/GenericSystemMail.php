<?php

namespace App\Mail;

use App\Services\EmailContentFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericSystemMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $recipientEmail;
    public $subject;
    public string $greeting;
    public array $bodyLines;
    public ?array $action;
    public ?string $replyToEmail;
    public ?string $replyToName;
    public ?string $unsubscribeUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(
        string $recipientEmail,
        string $subject,
        string $greeting,
        array $bodyLines,
        ?array $action = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        ?string $unsubscribeUrl = null
    ) {
        $this->recipientEmail = $recipientEmail;
        $this->subject = $subject;
        $this->greeting = $greeting;
        $this->bodyLines = $bodyLines;
        $this->action = $action;
        $this->replyToEmail = $replyToEmail;
        $this->replyToName = $replyToName;
        $this->unsubscribeUrl = $unsubscribeUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $envelope = new Envelope(
            subject: $this->subject,
        );

        if ($this->replyToEmail) {
            $envelope->replyTo = [
                new \Illuminate\Mail\Mailables\Address($this->replyToEmail, $this->replyToName ?? '')
            ];
        }

        return $envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.generic',
            with: [
                'subject' => $this->subject,
                'greeting' => $this->greeting,
                'bodyLines' => $this->bodyLines,
                'bodyBlocks' => app(EmailContentFormatter::class)->format($this->bodyLines),
                'action' => $this->action,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
