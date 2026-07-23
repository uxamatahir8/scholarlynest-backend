<?php

namespace App\Services\Notifications;

use App\Events\ArticleSubmitted;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;

class RecordArticleNotificationEvent
{
    public function __construct(private NotificationEventRecorder $recorder) {}

    public function handle(ArticleWorkflowEventOccurred|ArticleSubmitted $event): void
    {
        if ($event->notificationEventId) {
            return;
        }
        $eventType = $event instanceof ArticleSubmitted ? 'article.submitted' : $event->event;
        if (! app(NotificationTemplateRegistry::class)->has($eventType)) {
            return;
        }

        [$subjectType, $subjectId] = $this->subject($eventType, $event);
        $payload = $event instanceof ArticleSubmitted ? [] : $event->payload;
        if ($subjectId) {
            $payload['assignment_id'] ??= $subjectId;
        }

        $requestKey = request()?->header('Idempotency-Key');
        $dedupe = $requestKey
            ? hash('sha256', implode('|', [$eventType, $event->article->id, $requestKey]))
            : null;

        $recorded = $this->recorder->record(
            $eventType,
            $event->article,
            $event instanceof ArticleWorkflowEventOccurred ? $event->actor : $event->article->user,
            $payload,
            $subjectType,
            $subjectId,
            $event->notificationEventUuid,
            $dedupe,
            $event->notificationOccurredAt,
        );
        $event->notificationEventId = $recorded?->id;
    }

    private function subject(string $eventType, ArticleWorkflowEventOccurred|ArticleSubmitted $event): array
    {
        if ($event instanceof ArticleSubmitted) {
            return ['article', $event->article->id];
        }

        $id = (int) ($event->payload['assignment_id'] ?? 0);
        if (str_starts_with($eventType, 'review.') || $eventType === 'reviewer.assigned') {
            $id = $id ?: (int) ReviewerAssignment::query()->where('article_id', $event->article->id)->latest('id')->value('id');

            return ['reviewer_assignment', $id ?: null];
        }
        if (str_starts_with($eventType, 'sub_editor.')) {
            $id = $id ?: (int) SubEditorAssignment::query()->where('article_id', $event->article->id)->latest('id')->value('id');

            return ['sub_editor_assignment', $id ?: null];
        }
        if (str_starts_with($eventType, 'production.')) {
            $id = $id ?: (int) ProductionAssignment::query()->where('article_id', $event->article->id)->latest('id')->value('id');

            return ['production_assignment', $id ?: null];
        }

        return ['article', $event->article->id];
    }
}
