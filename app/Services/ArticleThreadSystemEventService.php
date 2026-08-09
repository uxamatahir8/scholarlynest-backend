<?php

namespace App\Services;

use App\Constants\ArticleThreadType;
use App\Events\ArticleSubmitted;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleThread;
use App\Models\User;
use Illuminate\Support\Str;

class ArticleThreadSystemEventService
{
    private const AUTHOR_SAFE = [
        'article.submitted', 'article.desk_rejected', 'article.under_review', 'transfer.requested',
        'transfer.accepted', 'transfer.rejected', 'revision.requested', 'article.resubmitted',
        'article.version_created', 'article.accepted', 'article.rejected', 'accepted_file_set.created',
        'production.completed', 'author.final_review_requested', 'author.final_review_denied',
        'author.final_review_approved', 'article.ready_for_publication',
        'article.issue_assigned', 'article.published', 'post_publication.recorded',
    ];

    public function handle(ArticleSubmitted|ArticleWorkflowEventOccurred $event): void
    {
        $article = $event->article->fresh();
        $actor = $event instanceof ArticleWorkflowEventOccurred ? $event->actor : $article->user;
        $eventKey = $event instanceof ArticleSubmitted ? 'article.submitted' : $event->event;
        $uuid = $event->notificationEventUuid;
        app(ArticleThreadService::class)->ensureForCurrentLifecycle($article, $actor);
        $article->threads()->get()->each(fn ($thread) => app(ArticleThreadService::class)->syncDefaultParticipants($thread, $actor));
        $this->recordLifecycleEvent($article, $eventKey, $actor, $uuid, $event instanceof ArticleWorkflowEventOccurred ? $event->payload : []);
    }

    public function recordLifecycleEvent(Article $article, string $event, ?User $actor, string $uuid, array $payload = []): void
    {
        $service = app(ArticleThreadService::class);
        $service->ensureForCurrentLifecycle($article, $actor);
        $threads = $article->threads()->whereIn('thread_type', $this->targetTypes($article, $event))->get();
        foreach ($threads as $thread) {
            if ($thread->thread_type === ArticleThreadType::REVIEWER_EDITORIAL) {
                $assignmentId = (int) ($payload['assignment_id'] ?? $payload['reviewer_assignment_id'] ?? 0);
                if (! $assignmentId || (int) $thread->context_id !== $assignmentId) {
                    continue;
                }
            }
            $this->createSystemMessage($thread, $event, $actor, $uuid.':'.$thread->id);
        }
    }

    public function createSystemMessage(ArticleThread $thread, string $event, ?User $actor, string $eventKey): void
    {
        $message = $thread->messages()->firstOrCreate(['event_key' => $eventKey], [
            'sender_id' => null, 'message_type' => $this->messageType($event),
            'body' => $this->safeBody($event), 'body_format' => 'plain_text',
            'audience_variant' => $thread->privacy_classification, 'is_system' => true,
        ]);
        if (! $message->wasRecentlyCreated) {
            return;
        }
        $thread->increment('message_count');
        $thread->update(['last_message_at' => $message->created_at]);
        ArticleAuditLog::create(['article_id' => $thread->article_id, 'actor_id' => $actor?->id,
            'event' => 'article_thread.system_message_created', 'payload' => ['thread_id' => $thread->id, 'message_id' => $message->id, 'system_event' => $event]]);
    }

    private function targetTypes(Article $article, string $event): array
    {
        if ($article->isDirectPublication()) {
            return [ArticleThreadType::DIRECT_PUBLICATION_INTERNAL];
        }
        $types = [ArticleThreadType::EDITORIAL_INTERNAL, ArticleThreadType::SYSTEM_ACTIVITY];
        if (in_array($event, self::AUTHOR_SAFE, true)) {
            $types[] = ArticleThreadType::AUTHOR_EDITOR;
        }
        if (str_starts_with($event, 'review.')) {
            $types[] = ArticleThreadType::REVIEWER_EDITORIAL;
        }
        if (str_starts_with($event, 'production.') || str_starts_with($event, 'accepted_file_set.')) {
            $types[] = ArticleThreadType::PRODUCTION_INTERNAL;
        }
        if (str_starts_with($event, 'author.final_review')) {
            $types[] = ArticleThreadType::AUTHOR_PROOF;
        }
        if (str_contains($event, 'published') || str_contains($event, 'publication') || $event === 'article.issue_assigned') {
            $types[] = ArticleThreadType::PUBLISHER_INTERNAL;
        }

        return array_values(array_unique($types));
    }

    private function safeBody(string $event): string
    {
        return match ($event) {
            'article.submitted' => 'The manuscript was submitted.',
            'reviewer.assigned' => 'A reviewer invitation was created.',
            'review.accepted' => 'A reviewer accepted the invitation.',
            'review.declined' => 'A reviewer declined the invitation.',
            'review.submitted' => 'A review was submitted.',
            'article.accepted' => 'The manuscript was accepted.',
            'article.rejected', 'article.desk_rejected' => 'An editorial decision was recorded.',
            'revision.requested' => 'A revision was requested.',
            'article.resubmitted' => 'A revised manuscript was submitted.',
            'production.assigned' => 'A production assignment was created.',
            'production.completed' => 'Copyediting was completed.',
            'author.final_review_requested' => 'The proof is ready for author review.',
            'author.final_review_approved' => 'The proof was approved.',
            'article.ready_for_publication' => 'The article is ready for publication.',
            'article.issue_assigned' => 'The issue assignment was updated.',
            'article.published', 'direct_publication.published' => 'The article was published.',
            'direct_publication.created' => 'The direct-publication draft was created.',
            'direct_publication.ready' => 'The direct-publication article was marked ready.',
            'direct_publication.scheduled' => 'Publication was scheduled.',
            'direct_publication.unpublished' => 'The article was unpublished.',
            'direct_publication.file_replaced' => 'The publication file was replaced.',
            'direct_publication.metadata_corrected' => 'Publication metadata was corrected.',
            default => Str::headline($event).'.',
        };
    }

    private function messageType(string $event): string
    {
        return str_contains($event, 'assign') ? 'assignment_update'
            : (str_contains($event, 'decision') || in_array($event, ['article.accepted', 'article.rejected'], true) ? 'decision_update'
                : (str_contains($event, 'publish') ? 'publication_update' : 'system_event'));
    }
}
