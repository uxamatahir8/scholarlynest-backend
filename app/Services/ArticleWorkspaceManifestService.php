<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Constants\LifecycleStatus;
use App\Models\Article;
use App\Models\ArticleThreadMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ArticleWorkspaceManifestService
{
    public function manifest(Article $article, User $viewer): array
    {
        $article->loadMissing([
            'magazine:id,title,slug',
            'versions:id,article_id,version_number,revision_number,status_snapshot,screening_status,submitted_at,created_at,accepted_marker,accepted_at',
            'activeAcceptedFileSet',
            'subEditorAssignments:id,article_id,article_version_id,sub_editor_id,status,accepted_at,declined_at,completed_at,revoked_at,recommendation',
            'reviewerAssignments:id,article_id,article_version_id,round_number,reviewer_id,invitee_name,invitee_email,status,invited_at,accepted_at,declined_at,completed_at,revoked_at,recommendation',
            'editorialDecisions:id,article_id,article_version_id,decision,decision_source,decision_date',
            'productionAssignments:id,article_id,article_version_id,user_id,role,status,due_date,completed_at,revoked_at',
            'proofRounds:id,article_id,article_version_id,round_number,status,requested_at,due_at,responded_at,approved_at',
            'auditLogs:id,article_id,event,from_status,to_status,created_at',
        ]);

        $projection = app(LifecycleStatusProjector::class)->projection($article, $viewer);
        $roles = $this->roles($article, $viewer);
        $acceptedVersionId = $article->activeAcceptedFileSet?->article_version_id ?: $article->accepted_version_id;

        if (! $article->isDirectPublication() && $roles['copy_editor']) {
            return $this->copyEditorManifest($article, $viewer, $projection, $acceptedVersionId);
        }

        $versions = $this->visibleVersions($article, $viewer, $roles, $acceptedVersionId);

        $tabs = $versions->map(fn ($version, int $index) => $this->versionTab(
            $article,
            $viewer,
            $roles,
            $version,
            $index,
            $acceptedVersionId,
            $projection
        ))->values();

        if (! $article->isDirectPublication() && ! $roles['reviewer']) {
            if ($roles['editorial'] || $roles['author'] || $roles['publisher']) {
                $tabs->push($this->articleTab('final-editorial-decision', 'final_editorial_decision', 'Final Editorial Decision',
                    $this->finalDecisionActions($roles, $projection)));
            }
            if ($roles['editorial'] || $roles['publisher'] || $roles['copy_editor']) {
                $tabs->push($this->articleTab('copy-editing', 'copy_editing', 'Copy Editing',
                    $this->copyEditingActions($article, $viewer, $roles)));
            }
            if ($roles['editorial'] || $roles['publisher'] || $roles['copy_editor'] || $roles['author']) {
                $tabs->push($this->articleTab('proofreading', 'proofreading', 'Proofreading',
                    $this->proofreadingActions($article, $roles), [
                        'rounds' => $article->proofRounds->sortBy('round_number')->map(fn ($round) => [
                            'id' => $round->id,
                            'round_number' => $round->round_number,
                            'status' => $round->status,
                        ])->values()->all(),
                    ]));
            }
        } elseif ($article->isDirectPublication()) {
            if ($roles['super'] || $roles['publisher']) {
                $tabs->push($this->articleTab('workflow-history', 'workflow_history', 'Workflow History', []));
                $tabs->push($this->articleTab('communication', 'communication', 'Communication', [], [
                    'unread_count' => $this->communicationUnreadCount($article, $viewer),
                ]));
            }

            return $this->response($article, $projection, $acceptedVersionId, $tabs, $versions);
        }

        if (! $roles['reviewer'] && ($roles['editorial'] || $roles['publisher'] || $roles['copy_editor'])) {
            $tabs->push($this->articleTab('workflow-history', 'workflow_history', 'Workflow History', []));
        }
        $tabs->push($this->articleTab('communication', 'communication', 'Communication', [], [
            'unread_count' => $this->communicationUnreadCount($article, $viewer),
        ]));

        return $this->response($article, $projection, $acceptedVersionId, $tabs, $versions);
    }

    public function canAccessAcceptedManuscript(Article $article, User $viewer): bool
    {
        if ($article->isDirectPublication() || ! $viewer->hasRole('copy_editor')) {
            return false;
        }

        $acceptedVersionId = $article->activeAcceptedFileSet?->article_version_id ?: $article->accepted_version_id;
        if (! $acceptedVersionId || ! $article->versions()->whereKey($acceptedVersionId)->exists()) {
            return false;
        }

        $hasActiveAssignment = $article->productionAssignments()
            ->where('user_id', $viewer->id)
            ->where('role', 'copy_editor')
            ->whereNull('revoked_at')
            ->whereNull('completed_at')
            ->whereIn('status', ['assigned', 'pending', 'in_progress', 'correction_required'])
            ->exists();

        return $hasActiveAssignment;
    }

    private function copyEditorManifest(Article $article, User $viewer, array $projection, ?int $acceptedVersionId): array
    {
        if (! $this->canAccessAcceptedManuscript($article, $viewer)) {
            return $this->response($article, $projection, $acceptedVersionId, collect(), collect());
        }

        $tabs = collect([
            $this->articleTab('copyeditor-manuscript', 'accepted_manuscript', 'Manuscript Information', [], [
                'accepted_version_id' => $acceptedVersionId,
                'capabilities' => [
                    'view' => true,
                    'download_files' => true,
                ],
            ]),
            $this->articleTab('copy-editing', 'copy_editing', 'Copy Editing',
                $this->copyEditingActions($article, $viewer, $this->roles($article, $viewer))),
        ]);

        if ($article->proofRounds->isNotEmpty()) {
            $tabs->push($this->articleTab('proofreading', 'proofreading', 'Proofreading',
                $this->proofreadingActions($article, $this->roles($article, $viewer)), [
                    'rounds' => $article->proofRounds->sortBy('round_number')->map(fn ($round) => [
                        'id' => $round->id,
                        'round_number' => $round->round_number,
                        'status' => $round->status,
                    ])->values()->all(),
                ]));
        }

        $tabs->push($this->articleTab('workflow-history', 'workflow_history', 'Workflow History', []));
        $tabs->push($this->articleTab('communication', 'communication', 'Communication', [], [
            'unread_count' => $this->communicationUnreadCount($article, $viewer),
        ]));

        return $this->response($article, $projection, $acceptedVersionId, $tabs, collect());
    }

    private function response(Article $article, array $projection, ?int $acceptedVersionId, Collection $tabs, Collection $versions): array
    {
        return [
            'article' => [
                'id' => $article->id,
                'tracking_code' => $article->tracking_code,
                'title' => $article->title,
                'status' => [
                    'code' => $projection['canonical'],
                    'label' => $projection['canonical_label'],
                ],
                'publication' => $article->magazine ? [
                    'id' => $article->magazine->id,
                    'name' => $article->magazine->title,
                ] : null,
                'submission_date' => optional($versions->sortBy('version_number')->first())->submitted_at?->toISOString()
                    ?? $article->created_at?->toISOString(),
                'direct_publication' => $article->isDirectPublication(),
            ],
            'workflow_progress' => $projection,
            'current_version_id' => $article->current_version_id,
            'accepted_version_id' => $acceptedVersionId,
            'selected_review_round' => (int) ($article->reviewerAssignments->where('article_version_id', $article->current_version_id)->max('round_number') ?: 1),
            'tabs' => $tabs->values()->all(),
        ];
    }

    private function roles(Article $article, User $viewer): array
    {
        $super = $viewer->hasRole(['super_admin', 'admin']);
        $editorial = $super || $viewer->can('approve', $article);
        $author = (int) $article->user_id === (int) $viewer->id
            || $article->articleAuthors()->where(fn ($query) => $query->where('user_id', $viewer->id)->orWhere('co_author_email', $viewer->email))->exists();
        $subEditor = $viewer->hasRole('sub_editor');
        $reviewer = $viewer->hasRole('reviewer') && ! $editorial;
        $copyEditor = $viewer->hasRole('copy_editor') && ! $editorial;
        $publisher = $viewer->hasRole('publisher');

        return compact('super', 'editorial', 'author', 'subEditor', 'reviewer', 'copyEditor', 'publisher') + [
            'sub_editor' => $subEditor,
            'copy_editor' => $copyEditor,
        ];
    }

    private function visibleVersions(Article $article, User $viewer, array $roles, ?int $acceptedVersionId): Collection
    {
        $versions = $article->versions->sortBy('version_number')->values();
        if ($article->isDirectPublication()) {
            return $versions->filter(fn ($version) => (int) $version->id === (int) ($article->current_version_id ?: $acceptedVersionId))->values();
        }
        if ($roles['reviewer']) {
            $ids = $article->reviewerAssignments->where('reviewer_id', $viewer->id)->whereNull('revoked_at')
                ->whereIn('status', ['accepted', 'in_progress', 'review_in_progress', 'reopened', 'completed'])->pluck('article_version_id')->unique();

            return $versions->whereIn('id', $ids)->values();
        }
        if ($roles['sub_editor'] && ! $roles['editorial']) {
            $ids = $article->subEditorAssignments->where('sub_editor_id', $viewer->id)->whereNull('revoked_at')->pluck('article_version_id')->unique();

            return $versions->whereIn('id', $ids)->values();
        }
        if (($roles['copy_editor'] || $roles['publisher']) && ! $roles['editorial']) {
            return $versions->filter(fn ($version) => (int) $version->id === (int) $acceptedVersionId)->values();
        }

        return $versions;
    }

    private function versionTab(Article $article, User $viewer, array $roles, $version, int $index, ?int $acceptedVersionId, array $projection): array
    {
        $versionId = (int) $version->id;
        $accepted = $acceptedVersionId && $versionId === (int) $acceptedVersionId;
        $reviews = $article->reviewerAssignments->where('article_version_id', $versionId)->where('status', 'completed')
            ->sortBy(['round_number', 'id'])->values();
        if ($roles['reviewer']) {
            $reviews = $reviews->where('reviewer_id', $viewer->id)->values();
        }
        $sidebar = [[
            'key' => 'manuscript-information',
            'label' => 'Manuscript Information',
            'visible' => true,
            'available_actions' => [],
        ]];

        if (! $article->isDirectPublication() && ($roles['editorial'] || $roles['sub_editor'])) {
            $sidebar[] = ['key' => 'editorial-decision', 'label' => 'Editorial Decision', 'visible' => true,
                'available_actions' => $this->editorialActions($roles, $projection, $version, $article)];
            $sidebar[] = ['key' => 'sub-editor-recommendation', 'label' => 'Sub Editor Recommendation', 'visible' => true,
                'available_actions' => $this->subEditorActions($article, $viewer, $roles, $versionId)];
            $sidebar[] = ['key' => 'reviewers', 'label' => 'Reviewers', 'visible' => true,
                'available_actions' => $this->reviewerActions($article, $roles, $version)];
        }
        foreach ($reviews as $reviewIndex => $review) {
            $sidebar[] = [
                'key' => 'review-'.$review->id,
                'label' => 'Reviewer '.($reviewIndex + 1).' Review',
                'visible' => true,
                'review_id' => $review->id,
                'available_actions' => [],
            ];
        }

        $snapshotStatus = ArticleStatus::normalize($version->status_snapshot) ?: ArticleStatus::SUBMITTED;
        $versionStatus = $accepted
            ? ArticleStatus::ACCEPTED
            : ($snapshotStatus === ArticleStatus::ACCEPTED ? ArticleStatus::SUBMITTED : $snapshotStatus);

        return [
            'key' => 'version-'.$versionId,
            'type' => 'article_version',
            'label' => $this->versionLabel($article, $version, $index, $accepted),
            'version_id' => $versionId,
            'accepted' => $accepted,
            'is_accepted' => $accepted,
            'status' => [
                'code' => $versionStatus,
                'label' => ArticleStatus::AUTHOR_VISIBLE[$versionStatus] ?? Str::headline($versionStatus),
                'screening' => $version->screening_status,
            ],
            'submitted_at' => $version->submitted_at?->toISOString() ?? $version->created_at?->toISOString(),
            'review_round' => (int) ($article->reviewerAssignments->where('article_version_id', $versionId)->max('round_number') ?: 1),
            'review_status' => $this->reviewStatus($article->reviewerAssignments->where('article_version_id', $versionId)),
            'assignment_state' => $article->subEditorAssignments->where('article_version_id', $versionId)->sortByDesc('id')->first()?->status,
            'sidebar' => $sidebar,
        ];
    }

    private function versionLabel(Article $article, $version, int $index, bool $accepted): string
    {
        $sequence = max(1, (int) ($version->version_number ?: $index + 1));
        $label = $sequence === 1
            ? 'Initial Submission ('.$article->tracking_code.')'
            : $article->tracking_code.' – R'.$sequence;

        return $label.($accepted ? ' (Accepted)' : '');
    }

    private function editorialActions(array $roles, array $projection, $version, Article $article): array
    {
        if (! $roles['editorial'] || (int) $version->id !== (int) $article->current_version_id) {
            return [];
        }
        if ($projection['canonical'] === LifecycleStatus::AWAITING_INITIAL_SCREENING) {
            return ['screen', 'transfer', 'desk_reject'];
        }
        if (in_array($projection['canonical'], LifecycleStatus::TERMINAL, true)) {
            return [];
        }

        return ['assign_sub_editor'];
    }

    private function subEditorActions(Article $article, User $viewer, array $roles, int $versionId): array
    {
        $assignment = $article->subEditorAssignments->where('article_version_id', $versionId)->whereNull('revoked_at')->sortByDesc('id')->first();
        if (! $assignment || (int) $assignment->sub_editor_id !== (int) $viewer->id || $assignment->completed_at) {
            return [];
        }
        if (! $assignment->accepted_at && ! $assignment->declined_at) {
            return ['accept_assignment', 'decline_assignment'];
        }

        return $assignment->accepted_at ? ['submit_recommendation'] : [];
    }

    private function reviewerActions(Article $article, array $roles, $version): array
    {
        return ($roles['editorial'] || $roles['sub_editor'])
            && (int) $version->id === (int) $article->current_version_id
            && $version->screening_status === 'passed'
            ? ['invite_reviewer', 'resend_invitation', 'reinvite_reviewer', 'invite_for_revision_review']
            : [];
    }

    private function finalDecisionActions(array $roles, array $projection): array
    {
        if (! $roles['editorial'] || in_array($projection['canonical'], LifecycleStatus::TERMINAL, true)) {
            return [];
        }

        return ['submit_final_decision'];
    }

    private function copyEditingActions(Article $article, User $viewer, array $roles): array
    {
        if (! $article->accepted_version_id && ! $article->activeAcceptedFileSet) {
            return [];
        }
        $assignment = $article->productionAssignments->where('role', 'copy_editor')->whereNull('revoked_at')->sortByDesc('id')->first();
        if (! $assignment && ($roles['super'] || $roles['publisher'])) {
            return ['assign_copy_editor'];
        }
        if ($assignment && (int) $assignment->user_id === (int) $viewer->id && $assignment->status !== 'completed') {
            return ['complete_copyediting'];
        }

        return [];
    }

    private function proofreadingActions(Article $article, array $roles): array
    {
        $round = $article->proofRounds->sortByDesc('round_number')->first();
        if (! $round) {
            return [];
        }
        if ($roles['author'] && $round->status === 'awaiting_author') {
            return ['submit_proof_corrections', 'approve_proof'];
        }
        if (($roles['editorial'] || $roles['copy_editor']) && in_array($round->status, ['author_responded', 'correction_required'], true)) {
            return ['submit_corrected_proof', 'approve_proof'];
        }

        return [];
    }

    private function reviewStatus(Collection $assignments): string
    {
        if ($assignments->isEmpty()) {
            return 'not_started';
        }
        if ($assignments->every(fn ($assignment) => $assignment->status === 'completed')) {
            return 'completed';
        }

        return 'in_progress';
    }

    private function articleTab(string $key, string $type, string $label, array $actions, array $extra = []): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'visible' => true,
            'available_actions' => $actions,
        ] + $extra;
    }

    private function communicationUnreadCount(Article $article, User $viewer): int
    {
        $threadIds = app(ArticleThreadAccessService::class)->accessibleQuery($viewer)
            ->where('article_id', $article->id)->pluck('id');
        if ($threadIds->isEmpty()) {
            return 0;
        }

        return (int) ArticleThreadMessage::query()
            ->leftJoin('article_thread_read_states as read_states', function ($join) use ($viewer) {
                $join->on('read_states.thread_id', '=', 'article_thread_messages.thread_id')
                    ->where('read_states.user_id', '=', $viewer->id);
            })
            ->whereIn('article_thread_messages.thread_id', $threadIds)
            ->whereNull('article_thread_messages.deleted_at')
            ->where(fn ($query) => $query->whereNull('article_thread_messages.sender_id')->orWhere('article_thread_messages.sender_id', '!=', $viewer->id))
            ->whereRaw('article_thread_messages.id > COALESCE(read_states.last_read_message_id, 0)')
            ->count('article_thread_messages.id');
    }
}
