<?php

namespace App\Services\Notifications;

use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Gate;

class NotificationPresenter
{
    public function __construct(
        private NotificationTemplateRegistry $templates,
        private NotificationDeepLinkResolver $links,
    ) {}

    public function present(UserNotification $notification, User $user, ?bool $preauthorized = null): array
    {
        $data = $notification->render_data ?? [];
        $subjectAvailable = $preauthorized ?? $this->subjectAvailable($notification, $user);
        $expired = $notification->action_expires_at?->isPast() ?? false;
        $actionStatus = $notification->action_status;
        if ($actionStatus === 'pending' && $expired) {
            $actionStatus = 'expired';
        }
        $actionAvailable = $actionStatus === 'pending' && $subjectAvailable;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'category' => $notification->category,
            'priority' => $notification->priority,
            'severity' => $notification->severity,
            'title' => $notification->rendered_title ?: 'Notification unavailable',
            'body' => $notification->rendered_body ?: 'This historical notification cannot be rendered safely.',
            'context' => array_filter([
                'article_id' => $notification->article_id,
                'tracking_code' => $data['tracking_code'] ?? null,
                'publication' => $data['publication'] ?? null,
                'due_at' => $data['due_at'] ?? null,
            ], fn ($value) => $value !== null),
            'created_at' => $notification->created_at?->toISOString(),
            'read_at' => $notification->read_at?->toISOString(),
            'visibility' => $notification->archived_at ? 'archived' : ($notification->dismissed_at ? 'dismissed' : 'active'),
            'deep_link' => $subjectAvailable ? $this->links->resolve($notification, $user) : null,
            'action' => [
                'status' => $actionStatus,
                'label' => $actionAvailable ? 'Open workflow' : null,
                'available' => $actionAvailable,
                'expires_at' => $notification->action_expires_at?->toISOString(),
            ],
            'unavailable' => ! $subjectAvailable,
        ];
    }

    private function subjectAvailable(UserNotification $notification, User $user): bool
    {
        if ($notification->article_id) {
            $article = $notification->article;
            if (! $article || ! Gate::forUser($user)->allows('view', $article)) {
                return false;
            }
        }

        if ($notification->subject_type === 'support_ticket' && $notification->subject_id) {
            $ticket = SupportTicket::find($notification->subject_id);
            if (! $ticket || ! Gate::forUser($user)->allows('view', $ticket)) {
                return false;
            }
        }

        return true;
    }
}
