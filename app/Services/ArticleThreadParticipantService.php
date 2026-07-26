<?php

namespace App\Services;

use App\Models\ArticleAuditLog;
use App\Models\ArticleThread;
use App\Models\ArticleThreadParticipant;
use App\Models\ArticleThreadReadState;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ArticleThreadParticipantService
{
    public function __construct(private ArticleThreadAccessService $access) {}

    public function add(ArticleThread $thread, User $candidate, User $actor, string $accessLevel = 'reply'): ArticleThreadParticipant
    {
        if (! $this->access->participantIsEligible($candidate, $thread)) {
            throw ValidationException::withMessages(['user_id' => 'This user is not eligible for the thread context.']);
        }
        $participant = $thread->participants()->updateOrCreate(['user_id' => $candidate->id], [
            'participant_role' => $this->access->roleFor($candidate, $thread),
            'access_level' => $accessLevel, 'added_by' => $actor->id, 'added_at' => now(),
            'removed_by' => null, 'removed_at' => null,
        ]);
        ArticleThreadReadState::updateOrCreate(['thread_id' => $thread->id, 'user_id' => $candidate->id], ['last_read_at' => now()]);
        $this->audit($thread, $actor, 'article_thread.participant_added', $candidate->id);

        return $participant->load('user:id,name');
    }

    public function remove(ArticleThread $thread, ArticleThreadParticipant $participant, User $actor): void
    {
        $participant->update(['removed_by' => $actor->id, 'removed_at' => now()]);
        ArticleThreadReadState::where('thread_id', $thread->id)->where('user_id', $participant->user_id)->delete();
        $this->audit($thread, $actor, 'article_thread.participant_removed', $participant->user_id);
    }

    public function mute(ArticleThread $thread, ArticleThreadParticipant $participant, User $actor, bool $muted): ArticleThreadParticipant
    {
        abort_unless((int) $participant->user_id === (int) $actor->id || $this->access->canManage($actor, $thread), 403);
        $participant->update(['muted_at' => $muted ? now() : null]);
        $this->audit($thread, $actor, 'article_thread.participant_muted', $participant->user_id);

        return $participant;
    }

    private function audit(ArticleThread $thread, User $actor, string $event, int $userId): void
    {
        ArticleAuditLog::create(['article_id' => $thread->article_id, 'actor_id' => $actor->id, 'event' => $event,
            'payload' => ['thread_id' => $thread->id, 'participant_user_id' => $userId]]);
    }
}
