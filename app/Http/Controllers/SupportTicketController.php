<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplySupportTicketRequest;
use App\Http\Requests\StoreSupportTicketRequest;
use App\Http\Requests\UpdateSupportTicketStatusRequest;
use App\Models\MediaUploadSession;
use App\Models\Permission;
use App\Models\SupportTicket;
use App\Models\SupportTicketActivity;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Policies\SupportTicketPolicy;
use App\Services\Media\CleanUploadResolver;
use App\Services\Media\MediaStorageService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupportTicketController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private SupportTicketPolicy $policy
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdminEndpoint = str_starts_with($request->path(), 'api/admin/');
        if ($isAdminEndpoint && !$this->policy->viewAny($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $tickets = SupportTicket::query()
            ->with(['user:id,name,email', 'lastRepliedBy:id,name'])
            ->visibleTo($user)
            ->status($request->query('status'))
            ->issueType($request->query('issue_type'))
            ->search($request->query('search'))
            ->latestActivity()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => collect($tickets->items())->map(fn (SupportTicket $ticket) => $this->ticketSummary($ticket, $user))->values(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
            'filters' => [
                'statuses' => SupportTicket::STATUSES,
                'issue_types' => SupportTicket::ISSUE_TYPES,
            ],
        ]);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $user = $request->user();
        $uploads = $this->cleanUploads($user, $request->input('attachments', []));

        $ticket = DB::transaction(function () use ($request, $user, $uploads) {
            $ticket = SupportTicket::create([
                'ticket_number' => '',
                'user_id' => $user->id,
                'issue_type' => $request->issue_type,
                'title' => $request->title,
                'details' => $request->details,
                'status' => 'submitted',
            ]);

            $this->attachUploads($ticket, null, $uploads, $user);
            $this->activity($ticket, $user, 'ticket_created', null, 'submitted');

            return $ticket->fresh(['user:id,name,email', 'attachments.uploadSession']);
        });

        $this->notifyTicketCreated($ticket);

        return response()->json([
            'message' => 'Support ticket submitted.',
            'ticket' => $this->ticketDetail($ticket, $user),
        ], 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        if (!$this->policy->view($request->user(), $ticket)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'ticket' => $this->ticketDetail($this->loadTicket($ticket), $request->user()),
        ]);
    }

    public function messages(Request $request, SupportTicket $ticket): JsonResponse
    {
        if (!$this->policy->view($request->user(), $ticket)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $ticket = $this->loadTicket($ticket);

        return response()->json([
            'data' => $ticket->messages
                ->filter(fn ($message) => !$message->is_internal_note || $this->policy->viewAny($request->user()))
                ->map(fn ($message) => $this->messagePayload($message, $request->user()))
                ->values(),
        ]);
    }

    public function reply(ReplySupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->reply($user, $ticket)) {
            return response()->json(['message' => $ticket->status === 'closed' ? 'Closed tickets cannot be replied to by the ticket owner.' : 'Forbidden.'], 403);
        }

        $uploads = $this->cleanUploads($user, $request->input('attachments', []));

        $message = DB::transaction(function () use ($request, $ticket, $user, $uploads) {
            $message = SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'is_internal_note' => false,
            ]);

            $ticket->update([
                'last_reply_at' => now(),
                'last_replied_by_id' => $user->id,
            ]);
            $this->attachUploads($ticket, $message, $uploads, $user);
            $this->activity($ticket, $user, 'reply_added');

            return $message->fresh(['user.role', 'attachments.uploadSession']);
        });

        $this->notifyTicketReplied($ticket->fresh('user'), $user);

        return response()->json([
            'message' => 'Reply added.',
            'reply' => $this->messagePayload($message, $user),
            'ticket' => $this->ticketDetail($this->loadTicket($ticket), $user),
        ], 201);
    }

    public function updateStatus(UpdateSupportTicketStatusRequest $request, SupportTicket $ticket): JsonResponse
    {
        $user = $request->user();
        if (!$this->policy->updateStatus($user, $ticket)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $old = $ticket->status;
        $new = $request->status;

        DB::transaction(function () use ($ticket, $user, $old, $new) {
            $ticket->update([
                'status' => $new,
                'closed_at' => $new === 'closed' ? now() : null,
                'closed_by_id' => $new === 'closed' ? $user->id : null,
            ]);
            $this->activity($ticket, $user, $new === 'closed' ? 'ticket_closed' : ($old === 'closed' ? 'ticket_reopened' : 'status_changed'), $old, $new);
        });

        $this->notifyStatusChanged($ticket->fresh('user'), $user, $old, $new);

        return response()->json([
            'message' => 'Ticket status updated.',
            'ticket' => $this->ticketDetail($this->loadTicket($ticket), $user),
        ]);
    }

    public function activities(Request $request, SupportTicket $ticket): JsonResponse
    {
        if (!$this->policy->viewActivity($request->user(), $ticket)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'data' => $ticket->activities()->with('actor:id,name')->orderBy('created_at')->get()
                ->map(fn ($activity) => $this->activityPayload($activity))
                ->values(),
        ]);
    }

    public function downloadAttachment(Request $request, SupportTicketAttachment $attachment)
    {
        if (!$this->policy->downloadAttachment($request->user(), $attachment)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $upload = $attachment->uploadSession;
        if (!$upload || $upload->status !== MediaUploadSession::STATUS_CLEAN || !$upload->s3_clean_key) {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        return app(MediaStorageService::class)->downloadResponse(
            $upload->s3_clean_key,
            $attachment->original_filename ?: $upload->safe_display_filename ?: $upload->original_filename,
            $attachment->mime_type ?: $upload->detected_mime_type ?: $upload->declared_mime_type ?: 'application/octet-stream',
            'attachment'
        );
    }

    private function cleanUploads(User $user, array $uploadIds): Collection
    {
        return collect($uploadIds)
            ->filter()
            ->unique()
            ->map(fn ($id) => app(CleanUploadResolver::class)->resolveOwned($user, $id, 'support_ticket_attachment'))
            ->values();
    }

    private function attachUploads(SupportTicket $ticket, ?SupportTicketMessage $message, Collection $uploads, User $user): void
    {
        foreach ($uploads as $upload) {
            SupportTicketAttachment::create([
                'support_ticket_id' => $ticket->id,
                'support_ticket_message_id' => $message?->id,
                'media_upload_session_id' => $upload->id,
                'uploaded_by_id' => $user->id,
                'original_filename' => $upload->safe_display_filename ?: $upload->original_filename,
                'mime_type' => $upload->detected_mime_type ?: $upload->declared_mime_type,
                'size_bytes' => $upload->expected_size_bytes,
            ]);
            $this->activity($ticket, $user, 'attachment_added', null, null, ['filename' => $upload->safe_display_filename ?: $upload->original_filename]);
        }
    }

    private function activity(SupportTicket $ticket, ?User $actor, string $type, ?string $old = null, ?string $new = null, array $metadata = []): void
    {
        SupportTicketActivity::create([
            'support_ticket_id' => $ticket->id,
            'actor_id' => $actor?->id,
            'activity_type' => $type,
            'old_value' => $old,
            'new_value' => $new,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function loadTicket(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load([
            'user:id,name,email,profile_image,role_id',
            'user.role:id,name,display_name',
            'lastRepliedBy:id,name',
            'closedBy:id,name',
            'attachments.uploadSession',
            'messages.user.role:id,name,display_name',
            'messages.attachments.uploadSession',
            'activities.actor:id,name',
        ]);
    }

    private function ticketSummary(SupportTicket $ticket, User $viewer): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'issue_type' => $ticket->issue_type,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'last_reply_at' => $ticket->last_reply_at,
            'last_replied_by' => $ticket->lastRepliedBy ? ['id' => $ticket->lastRepliedBy->id, 'name' => $ticket->lastRepliedBy->name] : null,
            'user' => $this->policy->viewAny($viewer) ? ['id' => $ticket->user?->id, 'name' => $ticket->user?->name, 'email' => $ticket->user?->email] : null,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ];
    }

    private function ticketDetail(SupportTicket $ticket, User $viewer): array
    {
        $ticket = $this->loadTicket($ticket);

        return $this->ticketSummary($ticket, $viewer) + [
            'details' => $ticket->details,
            'closed_at' => $ticket->closed_at,
            'closed_by' => $ticket->closedBy ? ['id' => $ticket->closedBy->id, 'name' => $ticket->closedBy->name] : null,
            'can_reply' => $this->policy->reply($viewer, $ticket),
            'can_update_status' => $this->policy->updateStatus($viewer, $ticket),
            'attachments' => $ticket->attachments->whereNull('support_ticket_message_id')->map(fn ($attachment) => $this->attachmentPayload($attachment))->values(),
            'messages' => $ticket->messages
                ->filter(fn ($message) => !$message->is_internal_note || $this->policy->viewAny($viewer))
                ->sortBy('created_at')
                ->map(fn ($message) => $this->messagePayload($message, $viewer))
                ->values(),
            'activities' => $ticket->activities->sortBy('created_at')->map(fn ($activity) => $this->activityPayload($activity))->values(),
            'statuses' => SupportTicket::STATUSES,
            'issue_types' => SupportTicket::ISSUE_TYPES,
        ];
    }

    private function messagePayload(SupportTicketMessage $message, User $viewer): array
    {
        return [
            'id' => $message->id,
            'message' => $message->message,
            'is_internal_note' => $message->is_internal_note,
            'author' => [
                'id' => $message->user?->id,
                'name' => $message->user?->name,
                'role' => $message->user?->role?->display_name ?: $message->user?->role?->name,
                'profile_image_url' => $message->user?->profile_image_url,
            ],
            'attachments' => $message->attachments->map(fn ($attachment) => $this->attachmentPayload($attachment))->values(),
            'created_at' => $message->created_at,
        ];
    }

    private function attachmentPayload(SupportTicketAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'original_filename' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'download_url' => url("/api/support/tickets/attachments/{$attachment->id}/download"),
            'created_at' => $attachment->created_at,
        ];
    }

    private function activityPayload(SupportTicketActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'activity_type' => $activity->activity_type,
            'old_value' => $activity->old_value,
            'new_value' => $activity->new_value,
            'metadata' => $activity->metadata,
            'actor' => $activity->actor ? ['id' => $activity->actor->id, 'name' => $activity->actor->name] : null,
            'created_at' => $activity->created_at,
        ];
    }

    private function supportRecipients(?User $actor = null): Collection
    {
        $permission = Permission::where('name', 'support_ticket_management')->first();

        return User::query()
            ->with('role')
            ->where(function ($query) use ($permission) {
                $query->whereHas('role', fn ($role) => $role->whereIn('name', ['super_admin', 'admin']));
                if ($permission) {
                    $query->orWhereHas('role.permissions', fn ($p) => $p->where('permissions.id', $permission->id));
                }
            })
            ->get()
            ->reject(fn (User $user) => $actor && (int) $user->id === (int) $actor->id)
            ->unique('email')
            ->values();
    }

    private function notifyTicketCreated(SupportTicket $ticket): void
    {
        $adminUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . "/admin/support-tickets/{$ticket->id}";
        $userUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . "/admin/support/{$ticket->id}";

        $this->notifications->send($ticket->user->email, "Support Ticket Received: {$ticket->ticket_number}", 'Dear ' . $ticket->user->name . ',', [
            'Your support ticket has been submitted successfully.',
            'Ticket Details: Ticket Number: ' . $ticket->ticket_number . '. Issue Type: ' . str_replace('_', ' ', $ticket->issue_type) . '. Title: ' . $ticket->title . '. Status: ' . str_replace('_', ' ', $ticket->status) . '. Submitted At: ' . $ticket->created_at->toDateTimeString() . '.',
            'Details: ' . strip_tags((string) $ticket->message),
            'Next Action: Our support team will review your ticket and reply in the ticket thread.',
        ], ['text' => 'View Ticket', 'url' => $userUrl], 'default', $ticket->user_id);

        foreach ($this->supportRecipients($ticket->user) as $recipient) {
            $this->notifications->send($recipient->email, "New Support Ticket: {$ticket->ticket_number} — {$ticket->title}", 'Dear ' . $recipient->name . ',', [
                'A new support ticket has been created.',
                'Ticket Details: Ticket Number: ' . $ticket->ticket_number . '. Issue Type: ' . str_replace('_', ' ', $ticket->issue_type) . '. Title: ' . $ticket->title . '. Status: ' . str_replace('_', ' ', $ticket->status) . '. Submitted By: ' . $ticket->user->name . '. Requester Email: ' . $ticket->user->email . '. Submitted At: ' . $ticket->created_at->toDateTimeString() . '.',
                'Details: ' . strip_tags((string) $ticket->message),
                'Next Action: Please review and respond from the admin support ticket panel.',
            ], ['text' => 'Open Ticket', 'url' => $adminUrl], 'default', $recipient->id);
        }
    }

    private function notifyTicketReplied(SupportTicket $ticket, User $actor): void
    {
        if ((int) $actor->id === (int) $ticket->user_id) {
            foreach ($this->supportRecipients($actor) as $recipient) {
                $this->notifications->send($recipient->email, "User Replied to Support Ticket: {$ticket->ticket_number}", 'Dear ' . $recipient->name . ',', [
                    'The ticket owner has replied to a support ticket.',
                    'Ticket Details: Ticket Number: ' . $ticket->ticket_number . '. Title: ' . $ticket->title . '. Current Status: ' . str_replace('_', ' ', $ticket->status) . '. Replied By: ' . $actor->name . '. Replied At: ' . now()->toDateTimeString() . '.',
                    'Next Action: Please review the latest reply and continue support handling.',
                ], ['text' => 'Open Ticket', 'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . "/admin/support-tickets/{$ticket->id}"], 'default', $recipient->id);
            }
            return;
        }

        $this->notifications->send($ticket->user->email, "Support Ticket Updated: {$ticket->ticket_number}", 'Dear ' . $ticket->user->name . ',', [
            'A support team member has replied to your ticket.',
            'Ticket Details: Ticket Number: ' . $ticket->ticket_number . '. Title: ' . $ticket->title . '. Current Status: ' . str_replace('_', ' ', $ticket->status) . '. Replied By: ' . $actor->name . '. Replied At: ' . now()->toDateTimeString() . '.',
            'Next Action: Please open the ticket to review the reply and respond if needed.',
        ], ['text' => 'View Ticket', 'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . "/admin/support/{$ticket->id}"], 'default', $ticket->user_id);
    }

    private function notifyStatusChanged(SupportTicket $ticket, User $actor, string $old, string $new): void
    {
        if ((int) $actor->id === (int) $ticket->user_id) {
            return;
        }

        $this->notifications->send($ticket->user->email, "Support Ticket Status Changed: {$ticket->ticket_number}", 'Dear ' . $ticket->user->name . ',', [
            'The status of your support ticket has been updated.',
            'Ticket Details: Ticket Number: ' . $ticket->ticket_number . '. Title: ' . $ticket->title . '. Previous Status: ' . str_replace('_', ' ', $old) . '. New Status: ' . str_replace('_', ' ', $new) . '. Updated By: ' . $actor->name . '. Updated At: ' . now()->toDateTimeString() . '.',
            'Next Action: Please open the ticket to review the latest status and any related replies.',
        ], ['text' => 'View Ticket', 'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . "/admin/support/{$ticket->id}"], 'default', $ticket->user_id);
    }
}
