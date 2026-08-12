<?php

namespace App\Services;

use App\Constants\ArticleThreadType;
use App\Models\Article;
use App\Models\ArticleThread;
use App\Models\ProductionAssignment;
use App\Models\ProofRound;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ArticleThreadAccessService
{
    public function accessibleQuery(User $user): Builder
    {
        $ids = ArticleThread::query()->whereHas('activeParticipants', fn ($query) => $query->where('user_id', $user->id))
            ->with(['article.magazine', 'activeParticipants'])->get()
            ->filter(fn (ArticleThread $thread) => $this->canView($user, $thread))->pluck('id');

        return ArticleThread::query()->whereIn('id', $ids);
    }

    public function canView(User $user, ArticleThread $thread): bool
    {
        $thread->loadMissing(['article.magazine', 'activeParticipants']);
        $article = $thread->article;
        if (! $article || ! $this->eligibleForType($user, $thread)) {
            return false;
        }

        return $thread->activeParticipants->contains(fn ($participant) => (int) $participant->user_id === (int) $user->id);
    }

    public function canReply(User $user, ArticleThread $thread): bool
    {
        if (! $this->canView($user, $thread) || $thread->thread_type === ArticleThreadType::SYSTEM_ACTIVITY || $thread->status !== 'active') {
            return false;
        }
        $participant = $thread->activeParticipants->firstWhere('user_id', $user->id);

        return $participant && in_array($participant->access_level, ['reply', 'manage'], true);
    }

    public function canManage(User $user, ArticleThread $thread): bool
    {
        if (! $this->canView($user, $thread)) {
            return false;
        }

        return $user->hasRole('super_admin')
            || ($user->hasRole('publisher') && in_array($thread->thread_type, [ArticleThreadType::PUBLISHER_INTERNAL, ArticleThreadType::DIRECT_PUBLICATION_INTERNAL], true))
            || ($user->isPublicationEditor() && ! $thread->article->isDirectPublication());
    }

    public function canEditMessage(User $user, ArticleThread $thread, $message): bool
    {
        return $this->canReply($user, $thread) && ! $message->is_system
            && (int) $message->sender_id === (int) $user->id
            && $message->created_at->gte(now()->subMinutes((int) config('article_threads.edit_window_minutes', 15)));
    }

    public function roleFor(User $user, ArticleThread $thread): string
    {
        if ($user->hasRole('super_admin')) {
            return 'super_admin';
        }
        if ($user->hasRole('publisher')) {
            return 'publisher';
        }
        if ($user->isPublicationEditor()) {
            return 'editor';
        }
        if ($user->hasRole('sub_editor')) {
            return 'sub_editor';
        }
        if ($user->hasRole('reviewer')) {
            return 'reviewer';
        }
        if ($user->hasRole('copy_editor')) {
            return 'copy_editor';
        }
        if ($this->isAuthor($user, $thread->article)) {
            return 'author';
        }

        return 'participant';
    }

    public function participantIsEligible(User $candidate, ArticleThread $thread): bool
    {
        return $this->eligibleForType($candidate, $thread);
    }

    private function eligibleForType(User $user, ArticleThread $thread): bool
    {
        $article = $thread->article;
        $super = $user->hasRole('super_admin');
        $editor = $user->isPublicationEditor() && $article->magazine && $user->canEditPublication($article->magazine);
        $publisher = $user->hasRole('publisher') && $this->hasPublicationScope($user, $article);
        $author = $this->isAuthor($user, $article);

        if ($article->isDirectPublication()) {
            return $thread->thread_type === ArticleThreadType::DIRECT_PUBLICATION_INTERNAL && ($super || $publisher);
        }

        return match ($thread->thread_type) {
            ArticleThreadType::AUTHOR_EDITOR => $super || $editor || $author,
            ArticleThreadType::EDITORIAL_INTERNAL, ArticleThreadType::REVIEW_COORDINATION_INTERNAL => $super || $editor || $this->activeSubEditor($user, $thread),
            ArticleThreadType::REVIEWER_EDITORIAL => $super || $editor || $this->assignedSubEditorForReview($user, $thread) || $this->activeReviewer($user, $thread),
            ArticleThreadType::PRODUCTION_INTERNAL => $super || $editor || $publisher || $this->activeProductionUser($user, $thread),
            ArticleThreadType::AUTHOR_PROOF => $super || $editor || $author || $this->activeProofUser($user, $thread),
            ArticleThreadType::PUBLISHER_INTERNAL => $super || $publisher || $editor,
            ArticleThreadType::SYSTEM_ACTIVITY => $super || $editor || $publisher || $author
                || $this->activeSubEditor($user, $thread) || $this->activeReviewer($user, $thread)
                || $this->activeProductionUser($user, $thread),
            ArticleThreadType::CUSTOM_RESTRICTED => $super || $editor,
            default => false,
        };
    }

    private function hasPublicationScope(User $user, Article $article): bool
    {
        return DB::table('magazine_user')->where('user_id', $user->id)->where('magazine_id', $article->magazine_id)
            ->where(fn ($query) => $query->where('role', 'publisher')->orWhereNull('role'))->exists();
    }

    private function isAuthor(User $user, Article $article): bool
    {
        return (int) $article->user_id === (int) $user->id
            || $article->articleAuthors()->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('co_author_email', $user->email))->exists();
    }

    private function activeSubEditor(User $user, ArticleThread $thread): bool
    {
        return SubEditorAssignment::query()->where('article_id', $thread->article_id)->where('sub_editor_id', $user->id)
            ->when($thread->article_version_id, fn ($query) => $query->where('article_version_id', $thread->article_version_id))
            ->whereNull('revoked_at')->whereIn('status', ['pending', 'accepted', 'in_progress'])->exists();
    }

    private function reviewerAssignment(ArticleThread $thread): ?ReviewerAssignment
    {
        if ($thread->context_type !== 'reviewer_assignment' || ! $thread->context_id) {
            return null;
        }

        return ReviewerAssignment::query()->whereKey($thread->context_id)->where('article_id', $thread->article_id)->first();
    }

    private function activeReviewer(User $user, ArticleThread $thread): bool
    {
        $assignment = $this->reviewerAssignment($thread);

        return $assignment && (int) $assignment->reviewer_id === (int) $user->id && ! $assignment->revoked_at
            && in_array($assignment->status, ['accepted', 'in_progress', 'review_in_progress', 'reopened'], true);
    }

    private function assignedSubEditorForReview(User $user, ArticleThread $thread): bool
    {
        $assignment = $this->reviewerAssignment($thread);

        return $assignment && $assignment->sub_editor_assignment_id
            && SubEditorAssignment::query()->whereKey($assignment->sub_editor_assignment_id)->where('sub_editor_id', $user->id)
                ->whereNull('revoked_at')->exists();
    }

    private function activeProductionUser(User $user, ArticleThread $thread): bool
    {
        return ProductionAssignment::query()->where('article_id', $thread->article_id)->where('user_id', $user->id)
            ->when($thread->article_version_id, fn ($query) => $query->where('article_version_id', $thread->article_version_id))
            ->whereNull('revoked_at')->whereIn('status', ['pending', 'in_progress'])->exists();
    }

    private function activeProofUser(User $user, ArticleThread $thread): bool
    {
        if ($thread->context_type !== 'proof_round' || ! $thread->context_id) {
            return false;
        }
        $proof = ProofRound::query()->whereKey($thread->context_id)->where('article_id', $thread->article_id)->first();

        return $proof && $proof->production_assignment_id
            && ProductionAssignment::query()->whereKey($proof->production_assignment_id)->where('user_id', $user->id)->whereNull('revoked_at')->exists();
    }
}
