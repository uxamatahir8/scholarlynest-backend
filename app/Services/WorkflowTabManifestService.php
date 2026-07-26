<?php

namespace App\Services;

use App\Constants\LifecycleStatus;
use App\Models\Article;
use App\Models\User;

class WorkflowTabManifestService
{
    public function manifest(Article $article, User $viewer): array
    {
        if ($article->isDirectPublication()) {
            return [
                'selected_version_id' => $article->current_version_id,
                'status' => app(LifecycleStatusProjector::class)->projection($article, $viewer),
                'tabs' => [],
                'capabilities' => array_fill_keys(['screen', 'transfer', 'desk_reject', 'assign_sub_editor', 'invite_reviewer', 'decide', 'submit_revision', 'proof_respond', 'prepare_publication'], false),
                'direct_publication_route' => '/admin/direct-publications/'.$article->id,
            ];
        }
        $projection = app(LifecycleStatusProjector::class)->projection($article, $viewer);
        $article->loadMissing(['versions.files', 'reviewerAssignments', 'subEditorAssignments', 'editorialDecisions', 'proofRounds', 'publicationRecords', 'activeAcceptedFileSet.items']);
        $editorial = $viewer->can('approve', $article) || $viewer->hasRole(['super_admin', 'admin']);
        $author = (int) $article->user_id === (int) $viewer->id || $article->articleAuthors()->where(fn ($q) => $q->where('user_id', $viewer->id)->orWhere('co_author_email', $viewer->email))->exists();
        $production = $article->productionAssignments()->where('user_id', $viewer->id)->whereNull('revoked_at')->exists();
        $publisher = $viewer->hasRole('publisher');

        $tabs = [[
            'id' => 'versions', 'label' => 'Versions', 'kind' => 'versions', 'permanent' => true,
            'count' => $article->versions->count(),
        ], [
            'id' => 'submission', 'label' => $article->tracking_code ?: 'Current submission', 'kind' => 'submission', 'permanent' => true,
        ]];
        $currentFiles = $article->currentVersion?->files ?? collect();
        foreach ([
            'additional' => ['Additional manuscript files', ['additional_manuscript_file']],
            'figures' => ['Figures', ['figure', 'image']],
            'supplementary' => ['Supplementary files', ['supplementary']],
        ] as $id => [$label, $types]) {
            $count = $currentFiles->whereIn('file_type', $types)->count();
            if ($count) {
                $tabs[] = ['id' => $id, 'label' => $label, 'kind' => 'files', 'count' => $count];
            }
        }
        $article->reviewerAssignments->where('status', 'completed')->each(function ($assignment, $index) use (&$tabs, $editorial, $author) {
            if ($editorial || $author) {
                $tabs[] = ['id' => 'review-'.$assignment->id, 'label' => 'Reviewer '.($index + 1).' review', 'kind' => 'review', 'record_id' => $assignment->id];
            }
        });
        if ($article->subEditorAssignments->whereNotNull('completed_at')->isNotEmpty() && $editorial) {
            $tabs[] = ['id' => 'recommendation', 'label' => 'Sub-editor recommendation', 'kind' => 'recommendation'];
        }
        if ($article->editorialDecisions->isNotEmpty()) {
            $tabs[] = ['id' => 'decision', 'label' => 'Final editorial decision', 'kind' => 'decision'];
        }
        if ($article->activeAcceptedFileSet && ($editorial || $production || $publisher)) {
            $tabs[] = ['id' => 'copyediting', 'label' => 'Copyediting', 'kind' => 'production'];
        }
        if ($article->proofRounds->isNotEmpty() && ($editorial || $production || $author)) {
            $tabs[] = ['id' => 'proofreading', 'label' => 'Proofreading', 'kind' => 'proof'];
        }
        if ($article->publicationRecords->isNotEmpty() && ($editorial || $publisher)) {
            $tabs[] = ['id' => 'publication', 'label' => 'Publication', 'kind' => 'publication'];
        }

        $terminal = in_array($projection['canonical'], LifecycleStatus::TERMINAL, true);

        return [
            'selected_version_id' => $article->current_version_id,
            'status' => $projection,
            'tabs' => $tabs,
            'capabilities' => [
                'screen' => $editorial && $projection['canonical'] === LifecycleStatus::AWAITING_INITIAL_SCREENING,
                'transfer' => $editorial && ! $terminal,
                'desk_reject' => $editorial && $projection['canonical'] === LifecycleStatus::AWAITING_INITIAL_SCREENING,
                'assign_sub_editor' => $editorial && ! $terminal,
                'invite_reviewer' => $editorial && $article->currentVersion?->screening_status === 'passed' && ! $terminal,
                'decide' => $editorial && ! $terminal,
                'submit_revision' => $author && in_array($projection['canonical'], [LifecycleStatus::MINOR_REVISION_REQUESTED, LifecycleStatus::MAJOR_REVISION_REQUESTED, LifecycleStatus::AWAITING_AUTHOR_REVISION], true),
                'proof_respond' => $author && $projection['canonical'] === LifecycleStatus::AWAITING_AUTHOR_PROOFREADING,
                'prepare_publication' => $publisher && in_array($projection['canonical'], [LifecycleStatus::PROOF_APPROVED, LifecycleStatus::READY_FOR_PUBLICATION, LifecycleStatus::AWAITING_ISSUE_ASSIGNMENT], true),
            ],
        ];
    }
}
