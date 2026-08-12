<?php

namespace App\Policies;

use App\Models\ArticleThread;
use App\Models\User;
use App\Services\ArticleThreadAccessService;

class ArticleThreadPolicy
{
    public function __construct(private ArticleThreadAccessService $access) {}

    public function viewAny(User $user): bool
    {
        return (bool) $user->role;
    }

    public function view(User $user, ArticleThread $thread): bool
    {
        return $this->access->canView($user, $thread);
    }

    public function create(User $user, ArticleThread $thread): bool
    {
        return $this->access->canManage($user, $thread);
    }

    public function manage(User $user, ArticleThread $thread): bool
    {
        return $this->access->canManage($user, $thread);
    }

    public function lock(User $user, ArticleThread $thread): bool
    {
        return $this->manage($user, $thread);
    }

    public function unlock(User $user, ArticleThread $thread): bool
    {
        return $this->manage($user, $thread);
    }

    public function archive(User $user, ArticleThread $thread): bool
    {
        return $this->manage($user, $thread);
    }

    public function reopen(User $user, ArticleThread $thread): bool
    {
        return $this->manage($user, $thread);
    }

    public function addParticipant(User $user, ArticleThread $thread): bool
    {
        return $this->manage($user, $thread);
    }

    public function removeParticipant(User $user, ArticleThread $thread): bool
    {
        return $this->manage($user, $thread);
    }

    public function viewParticipants(User $user, ArticleThread $thread): bool
    {
        return $this->view($user, $thread);
    }

    public function sendMessage(User $user, ArticleThread $thread): bool
    {
        return $this->access->canReply($user, $thread);
    }

    public function search(User $user, ArticleThread $thread): bool
    {
        return $this->view($user, $thread);
    }

    public function markRead(User $user, ArticleThread $thread): bool
    {
        return $this->view($user, $thread);
    }
}
