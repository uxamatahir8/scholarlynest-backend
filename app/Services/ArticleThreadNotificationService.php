<?php

namespace App\Services;

use App\Models\ArticleThread;
use App\Models\ArticleThreadMessage;
use App\Models\User;
use App\Services\Notifications\NotificationEventRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

class ArticleThreadNotificationService
{
    public function __construct(private NotificationEventRecorder $recorder, private ArticleThreadAccessService $access) {}

    public function messagePosted(ArticleThread $thread, ArticleThreadMessage $message, User $actor, array $mentionedIds): void
    {
        try {
            $participants = $thread->activeParticipants()->with('user.role')->where('user_id', '!=', $actor->id)->whereNull('muted_at')->get()
                ->filter(fn ($participant) => $participant->user && $this->access->canView($participant->user, $thread));
            foreach ($participants->groupBy(fn ($participant) => $this->variant($participant->user, $thread)) as $variant => $group) {
                $mentionGroup = $group->whereIn('user_id', $mentionedIds);
                $generalGroup = $group->whereNotIn('user_id', $mentionedIds);
                $this->record('article_thread.mentioned', $thread, $message, $actor, $variant, $mentionGroup->pluck('user_id')->all());
                $this->record('article_thread.message_posted', $thread, $message, $actor, $variant, $generalGroup->pluck('user_id')->all());
            }
        } catch (Throwable $exception) {
            Log::error('Article thread notification recording failed.', ['thread_id' => $thread->id, 'message_id' => $message->id, 'exception' => $exception]);
        }
    }

    private function record(string $event, ArticleThread $thread, ArticleThreadMessage $message, User $actor, string $variant, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->recorder->record($event, $thread->article, $actor, [
            'thread_id' => $thread->id, 'message_id' => $message->id,
            'recipient_user_ids' => $ids, 'recipient_privacy_variant' => $variant,
        ], 'article_thread', $thread->id, deduplicationKey: $event.':'.$message->id.':'.$variant);
    }

    private function variant(User $user, ArticleThread $thread): string
    {
        return match ($this->access->roleFor($user, $thread)) {
            'author' => 'author', 'reviewer' => 'reviewer', 'sub_editor' => 'sub_editor',
            'copy_editor', 'proofreader' => 'assignee', 'publisher' => 'publisher',
            'super_admin' => 'admin', default => 'editor',
        };
    }
}
