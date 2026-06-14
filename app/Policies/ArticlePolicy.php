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
        // Publicly published articles can be viewed by anyone
        if ($article->status === 'published') {
            return true;
        }

        // Non-published states require authentication
        if (!$user) {
            return false;
        }

        // Super admins, admins, and editors can view any article
        if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor')) {
            return true;
        }

        // Assigned magazine editors can view
        if ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) {
            $isAssigned = DB::table('magazine_user')
                ->where('user_id', $user->id)
                ->where('magazine_id', $article->magazine_id)
                ->exists();
            if ($isAssigned) {
                return true;
            }
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

        // Assigned magazine editors CANNOT edit (returns false unconditionally)
        if ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) {
            return false;
        }

        // Authors (and editing co-authors) must be blocked from modifying their manuscript

        // if status is not explicitly 'minor_review_rejected'
        if ($article->status !== 'minor_review_rejected') {
            return false;
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

    /**
     * Determine whether the user can approve/reject the article.
     */
    public function approve(User $user, Article $article): bool
    {
        if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor')) {
            return true;
        }

        if ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) {
            return DB::table('magazine_user')
                ->where('user_id', $user->id)
                ->where('magazine_id', $article->magazine_id)
                ->exists();
        }

        return false;
    }
}

