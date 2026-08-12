<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DirectPublicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->hasRole('publisher');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Article $article): bool
    {
        return $article->isDirectPublication() && $this->canAccessMagazine($user, (int) $article->magazine_id);
    }

    public function update(User $user, Article $article): bool
    {
        return $this->view($user, $article);
    }

    public function uploadFile(User $user, Article $article): bool
    {
        return $this->view($user, $article);
    }

    public function markReady(User $user, Article $article): bool
    {
        return $this->view($user, $article);
    }

    public function schedule(User $user, Article $article): bool
    {
        return $this->view($user, $article);
    }

    public function publish(User $user, Article $article): bool
    {
        return $this->view($user, $article);
    }

    public function unpublish(User $user, Article $article): bool
    {
        return $this->view($user, $article);
    }

    public function delete(User $user, Article $article): bool
    {
        return $this->view($user, $article);
    }

    public function canAccessMagazine(User $user, int $magazineId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }
        if (! $user->hasRole('publisher')) {
            return false;
        }

        return DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where('magazine_id', $magazineId)
            ->where(fn ($query) => $query->where('role', 'publisher')->orWhereNull('role'))
            ->exists();
    }
}
