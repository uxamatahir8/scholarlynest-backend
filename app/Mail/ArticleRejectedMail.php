<?php

namespace App\Mail;

use App\Models\Article;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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
        $subject = $this->rejectionType === 'fully_rejected' 
            ? 'Manuscript Decision: Fully Rejected' 
            : 'Manuscript Decision: Revisions Required (Minor Review)';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $typeLabel = $this->rejectionType === 'fully_rejected' 
            ? 'Fully Rejected (editing locked)' 
            : 'Minor Review (revisions required)';

        $bodyLines = [
            "We have completed the editorial review of your manuscript: <strong>" . e($this->article->title) . "</strong>.",
            "<strong>Decision Verdict:</strong> " . e($typeLabel),
        ];

        if ($this->feedbackNotes) {
            $bodyLines[] = "<strong>Editorial Feedback Notes:</strong>";
            $bodyLines[] = "<div style='background-color: #fafafa; border-left: 4px solid #e4e4e7; padding: 16px; font-style: italic; color: #52525b; margin: 16px 0;'>" . nl2br(e($this->feedbackNotes)) . "</div>";
        }

        if ($this->rejectionType === 'minor_review_rejected') {
            $bodyLines[] = "Your manuscript remains open for edits. You may modify the details and click save to automatically resubmit your manuscript for re-evaluation.";
        } else {
            $bodyLines[] = "This manuscript decision is final. Modifying this manuscript is now locked.";
        }

        return new Content(
            view: 'emails.generic',
            with: [
                'subject' => $this->envelope()->subject,
                'greeting' => 'Dear ' . $this->article->user->name . ',',
                'bodyLines' => $bodyLines,
                'action' => null,
                'unsubscribeUrl' => null,
            ],
        );
    }
}
