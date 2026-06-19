<?php

namespace App\Policies;

use App\Constants\ArticleStatus;
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
        if (ArticleStatus::normalize($article->status) === ArticleStatus::PUBLISHED) {
            return true;
        }

        // Non-published states require authentication
        if (!$user) {
            return false;
        }

        // Super admins and legacy admins can view any article.
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return true;
        }

        // Editors and legacy magazine editors can view assigned magazines.
        if ($this->isAssignedMagazineRole($user, $article, ['editor', 'magazine_editor'])) {
            return true;
        }

        // Primary author can view
        if ($article->user_id === $user->id) {
            return true;
        }

        // Sub Editor assignment
        if ($user->hasRole('sub_editor')) {
            $isAssignedSubEditor = DB::table('sub_editor_assignments')
                ->where('article_id', $article->id)
                ->where('sub_editor_id', $user->id)
                ->exists();
            if ($isAssignedSubEditor) {
                return true;
            }
        }

        // Reviewer assignment
        if ($user->hasRole('reviewer')) {
            $isAssignedReviewer = DB::table('reviewer_assignments')
                ->where('article_id', $article->id)
                ->where('reviewer_id', $user->id)
                ->exists();
            if ($isAssignedReviewer) {
                return true;
            }
        }

        // Production assignment
        if ($user->hasRole('copy_editor') || $user->hasRole('proofreader')) {
            $isAssignedProduction = DB::table('production_assignments')
                ->where('article_id', $article->id)
                ->where('user_id', $user->id)
                ->exists();
            if ($isAssignedProduction) {
                return true;
            }
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
        // Super admins and legacy admins can edit any article.
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return true;
        }

        // Editors use dedicated workflow endpoints for screening, decisions, and assignment.
        // Normal article content edits stay limited to authors during editable statuses.

        // Authors and editing co-authors can only modify drafts or requested revisions.
        if (!ArticleStatus::authorCanEdit($article->status)) {
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
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return true;
        }

        return $this->isAssignedMagazineRole($user, $article, ['editor', 'magazine_editor']);
    }

    private function isAssignedMagazineRole(User $user, Article $article, array $roles): bool
    {
        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->when(in_array('magazine_editor', $roles, true), fn ($collection) => $collection->push('editor'))
            ->unique()
            ->values()
            ->all();

        return DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where('magazine_id', $article->magazine_id)
            ->where(function ($query) use ($normalizedRoles) {
                $query->whereIn('role', $normalizedRoles)
                    ->orWhereNull('role');
            })
            ->exists();
    }
}
