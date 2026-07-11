<?php

namespace App\Mail;

use App\Constants\ArticleStatus;
use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use App\Services\EmailContentFormatter;

class ArticleRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Article $article;
    public string $rejectionType;
    public ?string $feedbackNotes;

    /**
     * Create a new message instance.
     */
    public function __construct(Article $article, string $rejectionType, ?string $feedbackNotes)
    {
        $this->article = $article;
        $this->rejectionType = $rejectionType;
        $this->feedbackNotes = $feedbackNotes;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $normalizedType = ArticleStatus::normalize($this->rejectionType);
        $subject = $normalizedType === ArticleStatus::REJECTED
            ? 'Manuscript Decision: Rejected'
            : 'Manuscript Decision: Revisions Required';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $normalizedType = ArticleStatus::normalize($this->rejectionType);
        $typeLabel = $normalizedType === ArticleStatus::REJECTED
            ? 'Rejected (editing locked)'
            : ArticleStatus::AUTHOR_VISIBLE[$normalizedType] ?? 'Revisions required';

        $bodyLines = [
            'The editorial review of your manuscript is complete.',
            '<br><strong>Manuscript Details:</strong>',
            '• <strong>Title:</strong> ' . e($this->article->title),
            '• <strong>Tracking Code:</strong> ' . e($this->article->tracking_code ?? 'Not assigned'),
            '<br><strong>Decision:</strong>',
            '• <strong>Outcome:</strong> ' . e($typeLabel),
            '• <strong>Decision Date:</strong> ' . now()->toDateTimeString(),
        ];

        if ($this->feedbackNotes) {
            $bodyLines[] = '<br><strong>Editorial Feedback:</strong>';
            $bodyLines[] = "<div style='background-color: #fafafa; border-left: 4px solid #e4e4e7; padding: 16px; font-style: italic; color: #52525b; margin: 16px 0;'>" . nl2br(e($this->feedbackNotes)) . "</div>";
        }

        if (ArticleStatus::isRevisionRequired($this->rejectionType)) {
            $bodyLines[] = 'Next Action: Revise the manuscript and submit the updated version from your article workflow page.';
        } else {
            $bodyLines[] = 'Next Action: This decision is final and editing for this manuscript is now locked.';
        }

        return new Content(
            view: 'emails.generic',
            with: [
                'subject' => $this->envelope()->subject,
                'greeting' => 'Dear ' . $this->article->user->name . ',',
                'bodyLines' => $bodyLines,
                'bodyBlocks' => app(EmailContentFormatter::class)->format($bodyLines),
                'action' => null,
                'unsubscribeUrl' => null,
            ],
        );
    }
}
