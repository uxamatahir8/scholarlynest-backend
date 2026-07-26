<?php

namespace App\Policies;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\User;
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
        if (! $user) {
            return false;
        }

        // Super admins and legacy admins can view any article.
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return true;
        }

        // Editors can view assigned magazines.
        if ($this->isAssignedMagazineRole($user, $article, ['editor'])) {
            return true;
        }

        // Assigned publishers use the same article context in the production and issue workflows.
        if ($user->hasRole('publisher') && $this->isAssignedMagazineRole($user, $article, ['publisher'])) {
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
                ->whereNull('revoked_at')
                ->whereIn('status', ['accepted', 'in_progress', 'review_in_progress', 'reopened'])
                ->exists();
            if ($isAssignedReviewer) {
                return true;
            }
        }

        // Production assignment
        if ($user->hasRole('copy_editor')) {
            $isAssignedProduction = DB::table('production_assignments')
                ->where('article_id', $article->id)
                ->where('user_id', $user->id)
                ->where('role', 'copy_editor')
                ->whereNull('revoked_at')
                ->whereIn('status', ['pending', 'in_progress', 'correction_required'])
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
        if (! ArticleStatus::isEditableStatus($article->status)) {
            return false;
        }

        // Super admins and legacy admins can edit any article.
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return true;
        }

        // Editors use dedicated workflow endpoints for screening, decisions, and assignment.
        // Normal article content edits stay limited to authors during editable statuses.

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

        return $this->isAssignedMagazineRole($user, $article, ['editor']);
    }

    private function isAssignedMagazineRole(User $user, Article $article, array $roles): bool
    {
        if (in_array('editor', $roles, true) && $user->isPublicationEditor()) {
            $article->loadMissing('magazine:id,publication_type');
            if (! $article->magazine || ! in_array($article->magazine->publication_type, $user->editorPublicationTypes(), true)) {
                return false;
            }
        }

        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
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
