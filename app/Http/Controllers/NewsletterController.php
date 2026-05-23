<?php

namespace App\Http\Controllers;

use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * POST /api/newsletter/subscribe
     * Subscribe a new email to the newsletter.
     */
    public function subscribe(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email|max:255',
            ]);

            $email = strtolower(trim($request->input('email')));

            $subscriber = NewsletterSubscriber::where('email', $email)->first();
            if ($subscriber) {
                if (!$subscriber->is_active) {
                    $subscriber->update(['is_active' => true]);
                    $this->sendWelcomeEmail($subscriber);
                    return response()->json([
                        'message' => 'Thank you for subscribing to our newsletter!',
                        'subscriber' => $subscriber
                    ], 211);
                }

                return response()->json([
                    'message' => 'This email is already subscribed to our newsletter!'
                ], 422);
            }

            $subscriber = NewsletterSubscriber::create([
                'email' => $email,
                'is_active' => true
            ]);

            $this->sendWelcomeEmail($subscriber);

            return response()->json([
                'message' => 'Thank you for subscribing to our newsletter!',
                'subscriber' => $subscriber
            ], 211);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            logger()->error("Newsletter subscription failure: " . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred processing your subscription request. Please try again later.'
            ], 500);
        }
    }

    private function sendWelcomeEmail(NewsletterSubscriber $subscriber): void
    {
        try {
            $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');
            $unsubscribeUrl = "{$frontendUrl}/unsubscribe/{$subscriber->token}";

            $bodyLines = [
                'Thank you for subscribing to the ScholarlyNest newsletter. We are thrilled to have you join our global community of researchers, educators, and scholars.',
                'You will now receive regular updates, including monthly highlights from our academic journals, trending articles, and platform developments.',
                'If you have any questions or feedback, please feel free to reach out to us at any time.',
                'Best regards,<br><strong>The ScholarlyNest Team</strong>'
            ];

            $this->notificationService->send(
                $subscriber->email,
                'Welcome to ScholarlyNest!',
                'Subscription Confirmed!',
                $bodyLines,
                null,
                'default',
                null,
                null,
                null,
                $unsubscribeUrl
            );
        } catch (\Exception $e) {
            logger()->error("Failed sending welcome email to {$subscriber->email}: " . $e->getMessage());
        }
    }

    /**
     * GET /api/newsletter/unsubscribe/{token}
     * Unsubscribe the user using their unique secure token. Renders styled HTML.
     */
    public function unsubscribe(string $token)
    {
        $subscriber = NewsletterSubscriber::where('token', $token)->first();

        if (!$subscriber) {
            if (request()->wantsJson()) {
                return response()->json([
                    'message' => 'The unsubscribe link is invalid or has expired.'
                ], 400);
            }

            $title = "Unsubscribe - ScholarlyNest";
            $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');
            $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: white; padding: 40px; border-radius: 16px; border: 1px solid #e4e4e7; max-width: 400px; width: 100%; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        h1 { font-family: Georgia, serif; font-size: 20px; color: #ef4444; margin-top: 0; }
        p { font-size: 13px; color: #52525b; line-height: 1.6; margin-bottom: 24px; }
        .btn { display: inline-block; background-color: #18181b; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Invalid Request</h1>
        <p>The unsubscribe link is invalid or has expired. If you need assistance, please contact ScholarlyNest support.</p>
        <a href="{$frontendUrl}" class="btn">Return to ScholarlyNest</a>
    </div>
</body>
</html>
HTML;
            return response($html, 400)->header('Content-Type', 'text/html');
        }

        $subscriber->update(['is_active' => false]);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => 'You have been successfully removed from our mailing list.'
            ], 200);
        }

        $title = "Unsubscribe - ScholarlyNest";
        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');
        $successHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: white; padding: 40px; border-radius: 16px; border: 1px solid #e4e4e7; max-width: 400px; width: 100%; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        h1 { font-family: Georgia, serif; font-size: 20px; color: #18181b; margin-top: 0; }
        p { font-size: 13px; color: #52525b; line-height: 1.6; margin-bottom: 24px; }
        .btn { display: inline-block; background-color: #18181b; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Unsubscribed Successfully</h1>
        <p>You have been successfully removed from our mailing list. You will no longer receive newsletter announcements from ScholarlyNest.</p>
        <a href="{$frontendUrl}" class="btn">Return to ScholarlyNest</a>
    </div>
</body>
</html>
HTML;
        return response($successHtml, 200)->header('Content-Type', 'text/html');
    }

    /**
     * GET /api/admin/newsletter/subscribers
     * List all newsletter subscribers (Admin only).
     */
    public function listSubscribers(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('newsletters.view-any')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $subscribers = NewsletterSubscriber::orderBy('created_at', 'desc')->get();

        // Get matching users to check roles and registration status
        $emails = $subscribers->pluck('email')->toArray();
        $users = \App\Models\User::whereIn('email', $emails)->with('roles')->get()->keyBy('email');

        $result = $subscribers->map(function ($sub) use ($users) {
            $userRecord = $users->get($sub->email);
            return [
                'id' => $sub->id,
                'email' => $sub->email,
                'token' => $sub->token,
                'created_at' => $sub->created_at,
                'updated_at' => $sub->updated_at,
                'is_registered' => !is_null($userRecord),
                'roles' => $userRecord ? $userRecord->roles->pluck('name')->toArray() : [],
            ];
        });

        return response()->json($result);
    }

    /**
     * GET /api/admin/newsletter/campaigns
     * List previously sent campaigns (Admin only).
     */
    public function listCampaigns(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('newsletters.view-any')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $campaigns = NewsletterCampaign::orderBy('created_at', 'desc')->get();
        return response()->json($campaigns);
    }

    /**
     * POST /api/admin/newsletter/send
     * Dispatch a custom newsletter email to all subscribers (Admin only).
     */
    public function sendCampaign(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('newsletters.send')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
            'recipients' => 'nullable|array',
            'recipients.*' => 'email',
        ]);

        $subject = $request->input('subject');
        $rawHtmlContent = $request->input('content');
        $recipientEmails = $request->input('recipients');

        if (is_array($recipientEmails) && count($recipientEmails) > 0) {
            $subscribers = NewsletterSubscriber::whereIn('email', $recipientEmails)
                                               ->where('is_active', true)
                                               ->get();
        } else {
            $subscribers = NewsletterSubscriber::where('is_active', true)->get();
        }

        $recipientsCount = $subscribers->count();

        // 1. Log the campaign database record
        $campaign = NewsletterCampaign::create([
            'subject' => $subject,
            'content' => $rawHtmlContent,
            'recipients_count' => $recipientsCount,
            'sent_at' => now(),
        ]);

        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');

        // 2. Loop send emails asynchronously
        foreach ($subscribers as $sub) {
            $unsubscribeUrl = "{$frontendUrl}/unsubscribe/{$sub->token}";

            try {
                $this->notificationService->send(
                    $sub->email,
                    $subject,
                    'ScholarlyNest Press',
                    [$rawHtmlContent],
                    null,
                    'default',
                    null,
                    null,
                    null,
                    $unsubscribeUrl
                );
            } catch (\Exception $e) {
                logger()->error("Failed sending newsletter campaign email to {$sub->email}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => "Newsletter campaign successfully dispatched to {$recipientsCount} subscribers.",
            'campaign' => $campaign
        ]);
    }
}
