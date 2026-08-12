<?php

namespace App\Services\Notifications;

use App\Models\NotificationEvent;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationEventProjector
{
    public function __construct(
        private NotificationRecipientResolver $recipients,
        private NotificationTemplateRegistry $templates,
        private NotificationPreferenceService $preferences,
        private NotificationService $email,
    ) {}

    public function project(int $eventId): void
    {
        if (! config('notification_system.features.enabled', true)) {
            return;
        }

        $maxAttempts = (int) config('notification_system.outbox.max_attempts', 5);
        NotificationEvent::query()->whereKey($eventId)->whereNull('processed_at')->whereNull('permanently_failed_at')
            ->where('attempt_count', '>=', $maxAttempts)
            ->update([
                'processing_at' => null,
                'permanently_failed_at' => now(),
                'failure_code' => 'max_attempts_exceeded',
                'last_error' => 'Projection permanently failed after the configured retry limit.',
            ]);

        $claimed = NotificationEvent::query()
            ->whereKey($eventId)->whereNull('processed_at')
            ->whereNull('permanently_failed_at')
            ->where('attempt_count', '<', $maxAttempts)
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('processing_at')->orWhere('processing_at', '<', now()->subMinutes((int) config('notification_system.outbox.stale_after_minutes', 10))))
            ->update(['processing_at' => now(), 'attempt_count' => DB::raw('attempt_count + 1')]);
        if ($claimed === 0) {
            return;
        }

        $event = NotificationEvent::with(['article.magazine', 'actor'])->findOrFail($eventId);

        try {
            $template = $this->templates->getVersion($event->event_type, $event->schema_version);
            foreach ($this->recipients->resolve($event) as $resolved) {
                $user = $resolved['user'];
                $variant = $resolved['privacy_variant'];
                $preference = $this->preferences->effectiveForEvent($user, $event->event_type);
                $emailMode = $template['legacyEmail']
                    ? 'off'
                    : ($template['mandatoryEmail'] ? 'immediate' : $preference['email']['mode']);
                if (! ($template['emailEnabled'] ?? true)) {
                    $emailMode = 'off';
                }
                if (! config('notification_system.features.email_projection', true) && ! $template['mandatoryEmail']) {
                    $emailMode = 'off';
                }
                if ($emailMode === 'immediate' && ! $template['mandatoryEmail'] && $this->preferences->isQuietHours($preference)) {
                    $emailMode = 'digest';
                }
                $inAppEnabled = config('notification_system.features.in_app', true) && $preference['in_app']['enabled'];
                if (! $inAppEnabled && $emailMode === 'off') {
                    continue;
                }

                $renderData = $this->renderData($event);
                $renderedTitle = $this->templates->render($template['title'], $renderData);
                $renderedBody = $this->templates->render($template['body'], $renderData);
                $hasAction = $this->actionAllowed($event->event_type, $variant, (bool) $template['action']);
                $dedupe = hash('sha256', implode('|', [$event->event_uuid, $user->id, $variant, 'in_app']));
                try {
                    $notification = UserNotification::firstOrCreate(
                        ['recipient_user_id' => $user->id, 'deduplication_key' => $dedupe],
                        [
                            'notification_event_id' => $event->id,
                            'type' => $event->event_type,
                            'category' => $template['category'],
                            'priority' => $template['priority'],
                            'severity' => $template['severity'],
                            'privacy_variant' => $variant,
                            'template_version' => $template['version'],
                            'title_key' => "notification.{$event->event_type}.title.v{$template['version']}",
                            'body_key' => "notification.{$event->event_type}.body.v{$template['version']}",
                            'rendered_title' => $renderedTitle,
                            'rendered_body' => $renderedBody,
                            'render_data' => $renderData,
                            'article_id' => $event->article_id,
                            'magazine_id' => $event->magazine_id,
                            'subject_type' => $event->subject_type,
                            'subject_id' => $event->subject_id,
                            'deep_link_key' => $this->routeFor($event, $variant, $template['route']),
                            'deep_link_params' => [
                                'article_id' => $event->article_id,
                                'article_slug' => $event->article?->slug,
                                'publication_slug' => $event->article?->magazine?->slug,
                                'publication_type' => $event->article?->magazine?->publication_type,
                                'ticket_id' => $event->subject_type === 'support_ticket' ? $event->subject_id : null,
                                'thread_id' => $event->payload['thread_id'] ?? null,
                            ],
                            'group_key' => $event->article_id ? "article:{$event->article_id}:{$event->event_type}" : "{$event->subject_type}:{$event->subject_id}",
                            'in_app_visible' => $inAppEnabled,
                            'email_mode' => $emailMode,
                            'digest_frequency' => $emailMode === 'digest' ? ($preference['digest_frequency'] ?: 'daily') : null,
                            'action_status' => $hasAction ? 'pending' : 'none',
                            'action_key' => $hasAction ? 'open_existing_workflow' : null,
                            'action_expires_at' => $event->payload['due_at'] ?? null,
                        ]
                    );
                } catch (QueryException) {
                    $notification = UserNotification::query()->where('recipient_user_id', $user->id)->where('deduplication_key', $dedupe)->first();
                }

                if ($notification && ! $notification->email_queued_at && $emailMode === 'immediate') {
                    $this->queueEmail($event, $notification, $user, $variant, $template, $renderData);
                    $notification->update(['email_queued_at' => now()]);
                }
            }

            $event->update(['processed_at' => now(), 'processing_at' => null, 'last_error' => null]);
        } catch (Throwable $exception) {
            $attempt = (int) $event->fresh()->attempt_count;
            $backoffs = config('notification_system.outbox.recovery_backoff_minutes', [1, 5, 15, 60, 240]);
            $failureCode = class_basename($exception);
            $permanent = $attempt >= $maxAttempts;
            $event->update([
                'processing_at' => null,
                'available_at' => $permanent ? $event->available_at : now()->addMinutes((int) ($backoffs[min($attempt - 1, count($backoffs) - 1)] ?? 60)),
                'permanently_failed_at' => $permanent ? now() : null,
                'failure_code' => $failureCode,
                'last_error' => $permanent
                    ? 'Projection permanently failed after the configured retry limit.'
                    : 'Projection failed and is scheduled for retry.',
            ]);
            Log::error('Notification event projection failed.', [
                'notification_event_id' => $event->id,
                'event_type' => $event->event_type,
                'attempt' => $attempt,
                'exception' => $exception,
            ]);
            throw $exception;
        }
    }

    private function actionAllowed(string $eventType, string $variant, bool $configured): bool
    {
        if (! $configured || $variant === 'co_author') {
            return false;
        }

        if ($variant === 'author' && ! in_array($eventType, [
            'revision.requested', 'author.final_review_requested',
        ], true)) {
            return false;
        }

        return true;
    }

    private function renderData(NotificationEvent $event): array
    {
        return [
            'tracking_code' => $event->article?->tracking_code ?? $event->payload['tracking_code'] ?? '—',
            'publication' => $event->article?->magazine?->title ?? 'ScholarlyNest',
            'article_title' => $event->article?->title,
            'ticket_reference' => $event->payload['ticket_reference'] ?? '—',
            'due_at' => $event->payload['due_at'] ?? $event->payload['due_date'] ?? null,
        ];
    }

    private function queueEmail(NotificationEvent $event, UserNotification $notification, $user, string $variant, array $template, array $data): void
    {
        $title = $notification->rendered_title;
        $body = $notification->rendered_body;
        $this->email->send(
            $user->email,
            $title,
            'Dear '.($user->name ?: 'ScholarlyNest user').',',
            [$body],
            null,
            $template['priority'] === 'critical' ? 'high' : 'default',
            $user->id,
            context: [
                'notification_event_id' => $event->id,
                'user_notification_id' => $notification->id,
                'purpose' => $event->event_type,
                'privacy_variant' => $variant,
                'deduplication_key' => hash('sha256', $event->event_uuid.'|'.$user->id.'|'.$variant.'|email'),
            ]
        );
    }

    private function routeFor(NotificationEvent $event, string $variant, ?string $configured): ?string
    {
        if ($event->subject_type === 'support_ticket') {
            return $variant === 'support_staff' ? 'admin.support.ticket' : 'support.ticket';
        }
        if (in_array($event->event_type, ['article.published', 'issue.published'], true) && $variant === 'author') {
            return 'article.public';
        }
        if (in_array($event->event_type, ['article.issue_assigned', 'article.ready_for_publication', 'issue.published'], true)
            && in_array($variant, ['author', 'editor', 'admin'], true)) {
            return 'article.workflow';
        }
        if ($configured === 'article.workflow') {
            return match ($variant) {
                'reviewer' => 'reviewer.desk',
                'sub_editor' => 'sub_editor.desk',
                'assignee' => 'copy_editor.desk',
                'publisher' => 'publisher.desk',
                default => $configured,
            };
        }

        return $configured;
    }
}
