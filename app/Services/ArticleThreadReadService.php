<?php

namespace App\Services;

use App\Models\ArticleThread;
use App\Models\ArticleThreadMessage;
use App\Models\ArticleThreadReadState;
use App\Models\User;

class ArticleThreadReadService
{
    public function unreadCount(ArticleThread $thread, User $user): int
    {
        $state = ArticleThreadReadState::where('thread_id', $thread->id)->where('user_id', $user->id)->first();

        return $thread->messages()->whereNull('deleted_at')->where(fn ($query) => $query->whereNull('sender_id')->orWhere('sender_id', '!=', $user->id))
            ->when($state?->last_read_message_id, fn ($query, $id) => $query->where('id', '>', $id))->count();
    }

    public function markRead(ArticleThread $thread, User $user, ?ArticleThreadMessage $through = null): ArticleThreadReadState
    {
        $through ??= $thread->messages()->latest('id')->first();

        return ArticleThreadReadState::updateOrCreate(['thread_id' => $thread->id, 'user_id' => $user->id], [
            'last_read_message_id' => $through?->id, 'last_read_at' => now(),
        ]);
    }
}
