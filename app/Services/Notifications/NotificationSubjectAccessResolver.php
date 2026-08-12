<?php

namespace App\Services\Notifications;

use App\Constants\ArticleStatus;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationSubjectAccessResolver
{
    public function resolve(Collection $notifications, User $user): Collection
    {
        $user->loadMissing(['role.permissions']);
        $access = collect();
        $articleNotifications = $notifications->whereNotNull('article_id');
        $articleIds = $articleNotifications->pluck('article_id')->map(fn ($id) => (int) $id)->unique()->values();

        if ($articleIds->isNotEmpty()) {
            $articles = $articleNotifications->pluck('article')->filter()->unique('id')->keyBy('id');
            $allowed = $this->allowedArticleIds($articles, $articleIds, $user);
            foreach ($articleIds as $articleId) {
                $access->put("article:{$articleId}", $allowed->contains($articleId));
            }
        }

        $ticketIds = $notifications->where('subject_type', 'support_ticket')->pluck('subject_id')
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ticketIds->isNotEmpty()) {
            $owns = DB::table('support_tickets')->whereIn('id', $ticketIds)->pluck('user_id', 'id');
            $canManage = $user->hasRole(['super_admin', 'admin']) || $user->hasPermission('support_ticket_management');
            foreach ($ticketIds as $ticketId) {
                $access->put("support_ticket:{$ticketId}", $canManage || (int) ($owns[$ticketId] ?? 0) === (int) $user->id);
            }
        }

        return $access;
    }

    public function key(UserNotification $notification): ?string
    {
        if ($notification->article_id) {
            return 'article:'.$notification->article_id;
        }
        if ($notification->subject_type === 'support_ticket' && $notification->subject_id) {
            return 'support_ticket:'.$notification->subject_id;
        }

        return null;
    }

    private function allowedArticleIds(Collection $articles, Collection $articleIds, User $user): Collection
    {
        $allowed = $articles->filter(fn ($article) => ArticleStatus::normalize($article->status) === ArticleStatus::PUBLISHED)
            ->keys()->map(fn ($id) => (int) $id);
        if ($user->hasRole(['super_admin', 'admin'])) {
            return $articleIds;
        }

        $allowed = $allowed->merge($articles->filter(fn ($article) => (int) $article->user_id === (int) $user->id)->keys());
        if ($user->isPublicationEditor() || $user->hasRole('publisher')) {
            $roles = $user->isPublicationEditor() ? ['editor'] : ['publisher'];
            $magazineIds = DB::table('magazine_user')->where('user_id', $user->id)
                ->where(function ($query) use ($roles) {
                    $query->whereIn('role', $roles)->orWhereNull('role');
                })->pluck('magazine_id');
            $allowed = $allowed->merge($articles->filter(function ($article) use ($user, $magazineIds) {
                if (! $magazineIds->contains($article->magazine_id)) {
                    return false;
                }

                return ! $user->isPublicationEditor()
                    || ($article->magazine && in_array($article->magazine->publication_type, $user->editorPublicationTypes(), true));
            })->keys());
        }

        $assignmentTable = match (true) {
            $user->hasRole('sub_editor') => ['sub_editor_assignments', 'sub_editor_id'],
            $user->hasRole('reviewer') => ['reviewer_assignments', 'reviewer_id'],
            $user->hasRole('copy_editor') => ['production_assignments', 'user_id'],
            default => null,
        };
        if ($assignmentTable) {
            $query = DB::table($assignmentTable[0])->where($assignmentTable[1], $user->id)->whereIn('article_id', $articleIds);
            if ($assignmentTable[0] === 'production_assignments') {
                $query->where('role', 'copy_editor');
            }
            $allowed = $allowed->merge($query->pluck('article_id'));
        }

        $allowed = $allowed->merge(DB::table('article_author')->whereIn('article_id', $articleIds)
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('co_author_email', $user->email))
            ->pluck('article_id'));

        return $allowed->map(fn ($id) => (int) $id)->unique()->values();
    }
}
