<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ContactController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * GET /api/contact-settings
     * Retrieve the current contact configuration values.
     */
    public function getSettings(): JsonResponse
    {
        $email = config('services.contact.email');
        $phone = config('services.contact.phone');
        $address = config('services.contact.address');

        // Normalize literal '\n' characters to actual newlines if needed
        $address = str_replace('\n', "\n", $address);

        return response()->json([
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
        ]);
    }

    /**
     * PUT /api/admin/contact-settings
     * Update the contact settings in the .env file.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:50',
            'address' => 'required|string',
        ]);

        try {
            $this->updateEnvKey('CONTACT_EMAIL', $request->input('email'));
            $this->updateEnvKey('CONTACT_PHONE', $request->input('phone'));
            $this->updateEnvKey('CONTACT_ADDRESS', $request->input('address'));

            // Clear the config cache so the new env values take effect immediately
            Artisan::call('config:clear');

            return response()->json([
                'message' => 'Contact settings updated successfully.',
                'settings' => [
                    'email' => $request->input('email'),
                    'phone' => $request->input('phone'),
                    'address' => $request->input('address'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save contact settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/contact
     * Submit contact form message. Save to DB and mail to contact email.
     */
    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'affiliation' => 'nullable|string|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        // 1. Save to Database
        $contactMessage = ContactMessage::create($request->only([
            'name', 'email', 'affiliation', 'subject', 'message'
        ]));

        // 2. Retrieve recipient email from env/config
        $recipientEmail = config('services.contact.email') ?: env('CONTACT_EMAIL', 'contact@scholarlynest.com');

        // 3. Send Email
        $senderName = $request->input('name');
        $senderEmail = $request->input('email');
        $senderAffiliation = $request->input('affiliation') ?: 'N/A';
        $msgSubject = $request->input('subject');
        $msgContent = nl2br(e($request->input('message')));

        $emailSubject = "[Contact Inquiry] {$msgSubject} - from {$senderName}";

        // Premium HTML Email Template
        $bodyLines = [
            '<div class="field-label">Sender Name</div><div class="field-value">' . htmlspecialchars($senderName) . '</div>',
            '<div class="field-label">Sender Email</div><div class="field-value">' . htmlspecialchars($senderEmail) . '</div>',
            '<div class="field-label">Academic Affiliation</div><div class="field-value">' . htmlspecialchars($senderAffiliation) . '</div>',
            '<div class="field-label">Subject Matter</div><div class="field-value">' . htmlspecialchars($msgSubject) . '</div>',
            '<div class="field-label">Message Details</div><div class="message-box">' . $msgContent . '</div>',
        ];

        try {
            $this->notificationService->send(
                $recipientEmail,
                $emailSubject,
                'ScholarlyNest Support Desk',
                $bodyLines,
                null,
                'default',
                null,
                $senderEmail,
                $senderName
            );
        } catch (\Exception $e) {
            logger()->error('Failed to send contact email: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Your inquiry has been submitted and saved successfully.',
            'data' => $contactMessage
        ]);
    }

    /**
     * GET /api/admin/contact-messages
     * Retrieve all contact submissions for the admin review panel.
     */
    public function getMessages(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $messages = ContactMessage::with(['replies.user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($messages);
    }

    /**
     * POST /api/admin/contact-messages/{id}/reply
     * Compose and send a reply email to a contact message, and log it in the database.
     */
    public function reply(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $contactMessage = ContactMessage::findOrFail($id);

        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $subject = $request->input('subject');
        $messageContent = $request->input('message');

        // 1. Save reply in database
        $reply = \App\Models\ContactReply::create([
            'contact_message_id' => $contactMessage->id,
            'user_id' => $user->id,
            'subject' => $subject,
            'message' => $messageContent,
        ]);

        // 2. Update contact message status to 'replied'
        $contactMessage->update([
            'status' => 'replied'
        ]);

        // 3. Send Email using NotificationService
        try {
            $this->notificationService->send(
                $contactMessage->email,
                $subject,
                "Dear " . $contactMessage->name . ",",
                [$messageContent],
                null,
                'default',
                $user->id,
                $user->email,
                $user->name
            );
        } catch (\Exception $e) {
            logger()->error('Failed to send contact reply email: ' . $e->getMessage());
            return response()->json([
                'message' => 'Reply saved, but sending email failed.',
                'reply' => $reply->load('user:id,name,email'),
                'contact_message' => $contactMessage,
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json([
            'message' => 'Reply sent and saved successfully.',
            'reply' => $reply->load('user:id,name,email'),
            'contact_message' => $contactMessage
        ]);
    }

    /**
     * GET /api/contact-subjects
     * Public endpoint to retrieve all contact subjects.
     */
    public function getSubjects(): JsonResponse
    {
        $subjects = \App\Models\ContactSubject::orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();
        return response()->json($subjects);
    }

    /**
     * POST /api/admin/contact-subjects
     * Create a new contact subject.
     */
    public function storeSubject(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'label' => 'required|string|max:255',
            'value' => 'required|string|max:255|unique:contact_subjects,value',
            'sort_order' => 'nullable|integer',
        ]);

        $subject = \App\Models\ContactSubject::create([
            'label' => $request->input('label'),
            'value' => $request->input('value'),
            'sort_order' => $request->input('sort_order', 0),
        ]);

        return response()->json([
            'message' => 'Contact subject created successfully.',
            'subject' => $subject
        ], 201);
    }

    /**
     * PUT /api/admin/contact-subjects/{id}
     * Update an existing contact subject.
     */
    public function updateSubject(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $subject = \App\Models\ContactSubject::findOrFail($id);

        $request->validate([
            'label' => 'required|string|max:255',
            'value' => 'required|string|max:255|unique:contact_subjects,value,' . $subject->id,
            'sort_order' => 'nullable|integer',
        ]);

        $subject->update([
            'label' => $request->input('label'),
            'value' => $request->input('value'),
            'sort_order' => $request->input('sort_order', 0),
        ]);

        return response()->json([
            'message' => 'Contact subject updated successfully.',
            'subject' => $subject
        ]);
    }

    /**
     * DELETE /api/admin/contact-subjects/{id}
     * Delete a contact subject.
     */
    public function deleteSubject(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $subject = \App\Models\ContactSubject::findOrFail($id);
        $subject->delete();

        return response()->json([
            'message' => 'Contact subject deleted successfully.'
        ]);
    }

    /**
     * Helper to write key-value pairs to the .env file.
     */
    protected function updateEnvKey(string $key, string $value): void
    {
        $path = base_path('.env');
        if (!file_exists($path)) {
            throw new \Exception('.env file not found.');
        }

        $content = file_get_contents($path);

        // Escape carriage returns and normalize newlines into literal \n string
        $escapedValue = str_replace(["\r\n", "\r", "\n"], '\n', $value);

        if (strpos($content, "{$key}=") !== false) {
            $content = preg_replace(
                "/^{$key}=.*/m",
                "{$key}=\"{$escapedValue}\"",
                $content
            );
        } else {
            $content .= "\n{$key}=\"{$escapedValue}\"\n";
        }

        file_put_contents($path, $content);
    }
}
