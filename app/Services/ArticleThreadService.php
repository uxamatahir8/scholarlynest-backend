<?php

namespace App\Services;

use App\Constants\ArticleThreadType;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleThread;
use App\Models\ReviewerAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArticleThreadService
{
    public function __construct(private ArticleThreadAccessService $access) {}

    public function ensureSubmissionThreads(Article $article, ?User $actor = null): Collection
    {
        if ($article->isDirectPublication()) {
            return collect([$this->ensureDirectPublicationThread($article, $actor)]);
        }
        $versionId = $article->current_version_id;

        return collect([
            $this->ensureDefault($article, ArticleThreadType::AUTHOR_EDITOR, 'Author and Editorial Communication', 'article_version', $versionId, $versionId, $actor),
            $this->ensureDefault($article, ArticleThreadType::EDITORIAL_INTERNAL, 'Editorial Discussion', 'article_version', $versionId, $versionId, $actor),
            $this->ensureDefault($article, ArticleThreadType::SYSTEM_ACTIVITY, 'Article Activity', 'article', $article->id, null, $actor),
        ]);
    }

    public function ensureDirectPublicationThread(Article $article, ?User $actor = null): ArticleThread
    {
        return $this->ensureDefault($article, ArticleThreadType::DIRECT_PUBLICATION_INTERNAL, 'Direct Publication Discussion', 'direct_publication', $article->id, $article->current_version_id, $actor);
    }

    public function ensureReviewerThread(ReviewerAssignment $assignment, ?User $actor = null): ArticleThread
    {
        $assignment->loadMissing('article');

        return $this->ensureDefault($assignment->article, ArticleThreadType::REVIEWER_EDITORIAL, 'Reviewer and Editorial Communication', 'reviewer_assignment', $assignment->id, $assignment->article_version_id, $actor);
    }

    public function ensureForCurrentLifecycle(Article $article, ?User $actor = null): void
    {
        if ($article->isDirectPublication()) {
            $this->ensureDirectPublicationThread($article, $actor);

            return;
        }
        $this->ensureSubmissionThreads($article, $actor);
        $article->reviewerAssignments()->whereNull('revoked_at')->whereIn('status', ['accepted', 'review_in_progress', 'reopened'])->get()
            ->each(fn ($assignment) => $this->ensureReviewerThread($assignment, $actor));
        $article->productionAssignments()->whereNull('revoked_at')->get()->each(function ($assignment) use ($article, $actor) {
            $this->ensureDefault($article, ArticleThreadType::PRODUCTION_INTERNAL, 'Production Discussion', 'production_assignment', $assignment->id, $assignment->article_version_id, $actor);
        });
        $article->proofRounds()->get()->each(function ($proof) use ($article, $actor) {
            $this->ensureDefault($article, ArticleThreadType::AUTHOR_PROOF, 'Author Proof Discussion — Round '.$proof->round_number, 'proof_round', $proof->id, $proof->article_version_id, $actor);
        });
        if ($article->publicationRecords()->exists()) {
            $record = $article->publicationRecords()->latest('id')->first();
            $this->ensureDefault($article, ArticleThreadType::PUBLISHER_INTERNAL, 'Publication Discussion', 'publication_record', $record->id, $record->article_version_id, $actor);
        }
    }

    public function createRestricted(Article $article, User $actor, array $data): ArticleThread
    {
        if ($article->isDirectPublication()) {
            throw ValidationException::withMessages(['thread_type' => 'Custom threads are not permitted for direct-publication articles.']);
        }
        $thread = ArticleThread::create([
            'article_id' => $article->id, 'article_version_id' => $data['article_version_id'] ?? null,
            'context_type' => 'article', 'context_id' => $article->id,
            'thread_type' => ArticleThreadType::CUSTOM_RESTRICTED,
            'privacy_classification' => ArticleThreadType::PRIVACY[ArticleThreadType::CUSTOM_RESTRICTED],
            'title' => trim($data['title']), 'status' => 'active', 'created_by' => $actor->id,
        ]);
        $this->syncDefaultParticipants($thread, $actor);
        $this->audit($thread, $actor, 'article_thread.created');

        return $thread;
    }

    public function transition(ArticleThread $thread, User $actor, string $target): ArticleThread
    {
        $allowed = ['active', 'locked', 'archived', 'closed'];
        if (! in_array($target, $allowed, true)) {
            abort(422, 'Unsupported thread state.');
        }
        if ($thread->thread_type === ArticleThreadType::SYSTEM_ACTIVITY && $target !== 'active') {
            abort(409, 'System activity cannot be closed.');
        }
        $before = $thread->status;
        $thread->forceFill([
            'status' => $target,
            'locked_by' => $target === 'locked' ? $actor->id : null,
            'locked_at' => $target === 'locked' ? now() : null,
            'archived_by' => $target === 'archived' ? $actor->id : null,
            'archived_at' => $target === 'archived' ? now() : null,
            'closed_at' => $target === 'closed' ? now() : null,
        ])->save();
        $this->audit($thread, $actor, 'article_thread.'.$target, ['previous_status' => $before]);

        return $thread->fresh();
    }

    public function syncDefaultParticipants(ArticleThread $thread, ?User $actor = null): void
    {
        $thread->loadMissing('article.magazine');
        $users = $this->eligibleDefaultUsers($thread)->unique('id');
        foreach ($users as $user) {
            $role = $this->access->roleFor($user, $thread);
            $access = $thread->thread_type === ArticleThreadType::SYSTEM_ACTIVITY ? 'read_only' : (in_array($role, ['super_admin', 'editor', 'publisher'], true) ? 'manage' : 'reply');
            $thread->participants()->updateOrCreate(['user_id' => $user->id], [
                'participant_role' => $role, 'access_level' => $access, 'added_by' => $actor?->id,
                'added_at' => now(), 'removed_by' => null, 'removed_at' => null,
            ]);
        }
    }

    private function ensureDefault(Article $article, string $type, string $title, string $contextType, ?int $contextId, ?int $versionId, ?User $actor): ArticleThread
    {
        $key = implode(':', [$article->id, $type, $contextType, $contextId ?: 0]);

        return DB::transaction(function () use ($article, $type, $title, $contextType, $contextId, $versionId, $actor, $key) {
            $thread = ArticleThread::firstOrCreate(['default_key' => $key], [
                'article_id' => $article->id, 'article_version_id' => $versionId,
                'context_type' => $contextType, 'context_id' => $contextId,
                'thread_type' => $type, 'privacy_classification' => ArticleThreadType::PRIVACY[$type],
                'title' => $title, 'status' => 'active', 'created_by' => $actor?->id,
            ]);
            if ($thread->wasRecentlyCreated) {
                $this->syncDefaultParticipants($thread, $actor);
                $this->audit($thread, $actor, 'article_thread.created');
            }

            return $thread;
        });
    }

    private function eligibleDefaultUsers(ArticleThread $thread): Collection
    {
        $article = $thread->article;
        $superAdmins = User::query()->whereHas('role', fn ($q) => $q->where('name', 'super_admin'))->get();
        $scoped = User::query()->with(['role', 'magazines'])->whereHas('magazines', fn ($q) => $q->where('magazines.id', $article->magazine_id))->get();
        $editors = $scoped->filter(fn (User $user) => $user->canEditPublication($article->magazine));
        $publishers = $scoped->filter(fn (User $user) => $user->hasRole('publisher'));
        $authors = User::query()->where(fn ($q) => $q->whereKey($article->user_id)->orWhereIn('id', $article->articleAuthors()->whereNotNull('user_id')->pluck('user_id')))->get();
        $subEditors = User::query()->whereIn('id', $article->subEditorAssignments()->whereNull('revoked_at')->pluck('sub_editor_id'))->get();
        $production = User::query()->whereIn('id', $article->productionAssignments()->whereNull('revoked_at')->pluck('user_id'))->get();

        return match ($thread->thread_type) {
            ArticleThreadType::DIRECT_PUBLICATION_INTERNAL => $superAdmins->merge($publishers)->push($article->directCreator)->filter(),
            ArticleThreadType::AUTHOR_EDITOR => $superAdmins->merge($editors)->merge($authors),
            ArticleThreadType::EDITORIAL_INTERNAL, ArticleThreadType::REVIEW_COORDINATION_INTERNAL => $superAdmins->merge($editors)->merge($subEditors),
            ArticleThreadType::REVIEWER_EDITORIAL => $this->reviewerParticipants($thread, $superAdmins, $editors),
            ArticleThreadType::PRODUCTION_INTERNAL => $superAdmins->merge($editors)->merge($publishers)->merge($production),
            ArticleThreadType::AUTHOR_PROOF => $superAdmins->merge($editors)->merge($authors)->merge($production),
            ArticleThreadType::PUBLISHER_INTERNAL => $superAdmins->merge($editors)->merge($publishers),
            ArticleThreadType::SYSTEM_ACTIVITY => $superAdmins->merge($editors)->merge($authors)->merge($publishers)->merge($subEditors)->merge($production),
            ArticleThreadType::CUSTOM_RESTRICTED => collect([$thread->creator])->merge($superAdmins)->filter(),
            default => collect(),
        };
    }

    private function reviewerParticipants(ArticleThread $thread, Collection $admins, Collection $editors): Collection
    {
        $assignment = ReviewerAssignment::with(['reviewer', 'subEditorAssignment.subEditor'])->find($thread->context_id);

        return $admins->merge($editors)->push($assignment?->reviewer)->push($assignment?->subEditorAssignment?->subEditor)->filter();
    }

    private function audit(ArticleThread $thread, ?User $actor, string $event, array $extra = []): void
    {
        ArticleAuditLog::create(['article_id' => $thread->article_id, 'actor_id' => $actor?->id, 'event' => $event,
            'payload' => $extra + ['thread_id' => $thread->id, 'thread_type' => $thread->thread_type, 'article_version_id' => $thread->article_version_id]]);
    }
}
