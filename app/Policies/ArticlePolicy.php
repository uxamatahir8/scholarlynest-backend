<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Article;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\DB;

class ArticlePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the article.
     */
    public function view(?User $user, Article $article): bool
    {
        // Publicly approved articles can be viewed by anyone
        if ($article->status === 'approved') {
            return true;
        }

        // Pending or rejected articles require authentication
        if (!$user) {
            return false;
        }

        // Super admins, admins, and editors can view any article
        if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor')) {
            return true;
        }

        // Primary author can view
        if ($article->user_id === $user->id) {
            return true;
        }

        // Co-authors linked by user_id or email can view
        return DB::table('article_author')
            ->where('article_id', $article->id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->orWhere('co_author_email', $user->email);
            })
            ->exists();
    }

    /**
     * Determine whether the user can update the article.
     */
    public function update(User $user, Article $article): bool
    {
        // Super admins, admins, and editors can edit any article
        if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor')) {
            return true;
        }

        // Primary author can edit
        if ($article->user_id === $user->id) {
            return true;
        }

        // Co-authors with explicit can_edit rights can edit
        return DB::table('article_author')
            ->where('article_id', $article->id)
            ->where('user_id', $user->id)
            ->where('can_edit', true)
            ->exists();
    }
}
