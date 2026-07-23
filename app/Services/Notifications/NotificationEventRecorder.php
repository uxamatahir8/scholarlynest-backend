<?php

namespace App\Services\Notifications;

use App\Jobs\ProcessNotificationEventJob;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\NotificationEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NotificationEventRecorder
{
    private const SAFE_PAYLOAD_KEYS = [
        'from_status', 'to_status', 'decision_id', 'assignment_id', 'sub_editor_assignment_id',
        'production_assignment_id', 'support_ticket_id', 'ticket_reference', 'article_version_id',
        'accepted_file_set_id', 'article_file_id', 'issue_id', 'recipient_user_id', 'recipient_user_ids',
        'due_at', 'due_date', 'due_date_version', 'reminder_type', 'action_type', 'publication_type',
        'tracking_code', 'revision_number', 'status', 'previous_status', 'is_escalation',
        'recipient_privacy_variant',
    ];

    public function __construct(private NotificationTemplateRegistry $templates) {}

    public function record(
        string $eventType,
        ?Article $article = null,
        ?User $actor = null,
        array $payload = [],
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $eventUuid = null,
        ?string $deduplicationKey = null,
        CarbonInterface|string|null $occurredAt = null,
        ?int $articleAuditLogId = null,
    ): ?NotificationEvent {
        if (! config('notification_system.features.enabled', true)) {
            return null;
        }
        if (! $this->templates->has($eventType)) {
            throw new InvalidArgumentException("Unsupported notification event type [{$eventType}].");
        }

        if ($subjectType !== null && ! in_array($subjectType, config('notification_system.subject_types', []), true)) {
            throw new InvalidArgumentException("Unsupported notification subject type [{$subjectType}].");
        }

        $eventUuid ??= (string) Str::uuid();
        $occurredAt = $occurredAt ? now()->parse($occurredAt) : now();
        $articleAuditLogId ??= $article ? ArticleAuditLog::query()->where('article_id', $article->id)->latest('id')->value('id') : null;

        $attributes = [
            'event_uuid' => $eventUuid,
            'deduplication_key' => $deduplicationKey,
            'event_type' => $eventType,
            'schema_version' => (int) (($this->templates->get($eventType)['schemaVersion'] ?? 1)),
            'actor_id' => $actor?->id,
            'article_id' => $article?->id,
            'magazine_id' => $article?->magazine_id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'article_audit_log_id' => $articleAuditLogId,
            'payload' => $this->sanitizePayload($payload),
            'occurred_at' => $occurredAt,
            'available_at' => now(),
        ];

        try {
            $event = NotificationEvent::create($attributes);
        } catch (QueryException $exception) {
            $event = NotificationEvent::query()
                ->where('event_uuid', $eventUuid)
                ->when($deduplicationKey, fn ($query) => $query->orWhere('deduplication_key', $deduplicationKey))
                ->first();

            if (! $event) {
                throw $exception;
            }
        }

        ProcessNotificationEventJob::dispatch($event->id)->afterCommit()->onQueue('default');

        return $event;
    }

    private function sanitizePayload(array $payload): array
    {
        $safe = Arr::only($payload, self::SAFE_PAYLOAD_KEYS);

        foreach ($safe as $key => $value) {
            if (is_array($value)) {
                $safe[$key] = array_values(array_filter(array_map(
                    fn ($item) => is_scalar($item) ? $item : null,
                    $value
                ), fn ($item) => $item !== null));
            } elseif (! is_scalar($value) && $value !== null) {
                unset($safe[$key]);
            } elseif (is_string($value)) {
                $safe[$key] = Str::limit(strip_tags($value), 500, '');
            }
        }

        return $safe;
    }
}
