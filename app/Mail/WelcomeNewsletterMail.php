<?php

namespace App\Mail;

use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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
    public function build(): self
    {
        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');
        $unsubscribeUrl = "{$frontendUrl}/unsubscribe/{$this->subscriber->token}";

        $emailBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <style>
    body { font-family: 'Roboto', sans-serif; background-color: #f4f4f5; margin: 0; padding: 0; }
    .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .header { background-color: #18181b; padding: 24px; text-align: center; }
    .header h2 { color: #ffffff; font-family: 'Roboto', sans-serif; margin: 0; font-size: 20px; letter-spacing: 0.05em; }
    .content { padding: 32px 24px; font-size: 14px; line-height: 1.6; color: #18181b; }
    .content h3 { font-family: 'Roboto', sans-serif; font-size: 18px; margin-top: 0; color: #18181b; }
    .footer { background-color: #fafafa; padding: 24px; font-size: 11px; text-align: center; color: #71717a; border-top: 1px solid #f4f4f5; }
    .unsubscribe-link { color: #3b82f6; text-decoration: underline; display: inline-block; margin-top: 10px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h2>ScholarlyNest</h2>
    </div>
    <div class="content">
      <h3>Subscription Confirmed!</h3>
      <p>Thank you for subscribing to the ScholarlyNest newsletter. We are thrilled to have you join our global community of researchers, educators, and scholars.</p>
      <p>You will now receive regular updates, including monthly highlights from our academic journals, trending articles, and platform developments.</p>
      <p>If you have any questions or feedback, please feel free to reach out to us at any time.</p>
      <p>Best regards,<br><strong>The ScholarlyNest Team</strong></p>
    </div>
    <div class="footer">
      You are receiving this because you subscribed to the ScholarlyNest newsletter.<br>
      <a href="{$unsubscribeUrl}" class="unsubscribe-link">Unsubscribe from this list</a>
    </div>
  </div>
</body>
</html>
HTML;

        return $this->subject('Welcome to ScholarlyNest!')
                    ->html($emailBody);
    }
}
