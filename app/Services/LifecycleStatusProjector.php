<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Constants\DirectPublicationStatus;
use App\Constants\LifecycleStatus;
use App\Models\Article;
use App\Models\User;

class LifecycleStatusProjector
{
    public function canonical(Article $article): string
    {
        if ($article->isDirectPublication()) {
            return $article->status;
        }
        $article->loadMissing([
            'pendingTransferRequest',
            'currentVersion',
            'currentVersion.reviewerAssignments',
            'currentVersion.subEditorAssignments',
            'currentVersion.editorialDecisions',
            'activeAcceptedFileSet',
            'productionAssignments',
            'proofRounds',
            'latestPublicationRecord',
        ]);

        $publication = $article->latestPublicationRecord;
        if ($publication?->status === 'unpublished') {
            return LifecycleStatus::UNPUBLISHED;
        }
        if ($publication?->status === 'published') {
            return LifecycleStatus::PUBLISHED;
        }
        if ($publication?->status === 'scheduled') {
            return LifecycleStatus::SCHEDULED_FOR_PUBLICATION;
        }
        if ($publication?->magazine_issue_id) {
            return LifecycleStatus::ASSIGNED_TO_ISSUE;
        }
        if ($publication?->status === 'preparing') {
            return LifecycleStatus::AWAITING_ISSUE_ASSIGNMENT;
        }

        $proof = $article->proofRounds->sortByDesc('round_number')->first();
        if ($proof?->status === 'approved') {
            return LifecycleStatus::READY_FOR_PUBLICATION;
        }
        if (in_array($proof?->status, ['corrections_requested', 'correction_in_progress'], true)) {
            return LifecycleStatus::PROOF_CORRECTIONS_IN_PROGRESS;
        }
        if (in_array($proof?->status, ['awaiting_author', 'resent'], true)) {
            return LifecycleStatus::AWAITING_AUTHOR_PROOFREADING;
        }

        if ($article->activeAcceptedFileSet) {
            $copyedit = $article->productionAssignments->where('role', 'copy_editor')->whereNull('revoked_at')->sortByDesc('id')->first();
            if ($copyedit && in_array($copyedit->status, ['pending', 'in_progress', 'correction_required'], true)) {
                return LifecycleStatus::COPYEDITING_IN_PROGRESS;
            }
            if (! $copyedit) {
                return LifecycleStatus::AWAITING_COPY_EDITOR_ASSIGNMENT;
            }
            if ($copyedit->status === 'completed' && ! $proof) {
                return LifecycleStatus::AWAITING_AUTHOR_PROOFREADING;
            }
        }

        $version = $article->currentVersion;
        $decision = $version?->editorialDecisions?->sortByDesc('id')->first();
        if ($decision) {
            return match ($decision->decision) {
                'accepted', 'accept', 'approved' => $article->activeAcceptedFileSet ? LifecycleStatus::AWAITING_COPY_EDITOR_ASSIGNMENT : LifecycleStatus::ACCEPTED,
                'rejected', 'reject' => LifecycleStatus::REJECTED,
                'minor_revision', 'minor_revision_required' => LifecycleStatus::MINOR_REVISION_REQUESTED,
                'major_revision', 'major_revision_required', 'revision_required' => LifecycleStatus::MAJOR_REVISION_REQUESTED,
                default => LifecycleStatus::AWAITING_EDITORIAL_DECISION,
            };
        }

        if ($article->pendingTransferRequest) {
            return LifecycleStatus::TRANSFER_REQUESTED;
        }
        if ($version?->screening_status === 'rejected') {
            return LifecycleStatus::DESK_REJECTED;
        }

        if ($version?->screening_status === 'passed') {
            $reviews = $version->reviewerAssignments ?? collect();
            $completed = $reviews->where('status', 'completed')->count();
            $active = $reviews->whereIn('status', ['pending', 'invited', 'accepted', 'in_progress', 'completed'])->count();
            if ($active === 0) {
                return LifecycleStatus::AWAITING_REVIEWER_INVITATION;
            }
            if ($completed > 0 && $completed < $active) {
                return LifecycleStatus::REVIEWS_PARTIALLY_COMPLETED;
            }
            if ($active > 0 && $completed === $active) {
                $subEditor = ($version->subEditorAssignments ?? collect())->whereNull('revoked_at')->sortByDesc('id')->first();
                if ($subEditor && ! $subEditor->completed_at) {
                    return LifecycleStatus::AWAITING_SUB_EDITOR_RECOMMENDATION;
                }

                return LifecycleStatus::AWAITING_EDITORIAL_DECISION;
            }
            if ($reviews->whereIn('status', ['accepted', 'in_progress'])->isNotEmpty()) {
                return LifecycleStatus::UNDER_REVIEW;
            }

            return LifecycleStatus::REVIEWER_INVITATIONS_PENDING;
        }

        return $this->legacyFallback($article);
    }

    public function projection(Article $article, ?User $viewer): array
    {
        $canonical = $this->canonical($article);
        if ($article->isDirectPublication()) {
            return [
                'canonical' => $canonical,
                'canonical_label' => DirectPublicationStatus::label($canonical),
                'role' => $viewer?->role?->name,
                'label' => DirectPublicationStatus::label($canonical),
                'action_required' => false,
                'action' => null,
                'publication_type' => 'Direct Publication',
            ];
        }
        $roles = collect([$viewer?->role?->name])->filter()->map(fn ($role) => str_replace('-', '_', $role));
        $role = $roles->first(fn ($candidate) => in_array($candidate, ['author', 'editor', 'sub_editor', 'reviewer', 'copy_editor', 'proofreader', 'publisher', 'super_admin', 'admin'], true)) ?? 'author';
        $action = match (true) {
            $role === 'author' && in_array($canonical, [LifecycleStatus::MINOR_REVISION_REQUESTED, LifecycleStatus::MAJOR_REVISION_REQUESTED, LifecycleStatus::AWAITING_AUTHOR_REVISION], true) => 'Submit revision',
            $role === 'author' && $canonical === LifecycleStatus::AWAITING_AUTHOR_PROOFREADING => 'Review proof',
            in_array($role, ['editor', 'super_admin', 'admin'], true) && $canonical === LifecycleStatus::AWAITING_INITIAL_SCREENING => 'Screen article',
            in_array($role, ['editor', 'super_admin', 'admin'], true) && $canonical === LifecycleStatus::AWAITING_EDITORIAL_DECISION => 'Submit editorial decision',
            $role === 'reviewer' && in_array($canonical, [LifecycleStatus::REVIEWER_INVITATIONS_PENDING, LifecycleStatus::UNDER_REVIEW], true) => 'Complete assigned review',
            $role === 'copy_editor' && $canonical === LifecycleStatus::COPYEDITING_IN_PROGRESS => 'Complete copyediting',
            $role === 'publisher' && in_array($canonical, [LifecycleStatus::READY_FOR_PUBLICATION, LifecycleStatus::AWAITING_ISSUE_ASSIGNMENT], true) => 'Prepare publication',
            default => null,
        };

        return [
            'canonical' => $canonical,
            'canonical_label' => LifecycleStatus::label($canonical),
            'role' => $role,
            'label' => $action ? 'Action required: '.$action : LifecycleStatus::label($canonical),
            'action_required' => $action !== null,
            'action' => $action,
        ];
    }

    public function synchronize(Article $article): string
    {
        $status = $this->canonical($article->fresh());
        if ($article->lifecycle_status !== $status) {
            $article->forceFill(['lifecycle_status' => $status])->saveQuietly();
        }

        return $status;
    }

    private function legacyFallback(Article $article): string
    {
        return match (ArticleStatus::normalize($article->status)) {
            ArticleStatus::DRAFT => LifecycleStatus::DRAFT,
            ArticleStatus::SUBMITTED, ArticleStatus::SCREENING => LifecycleStatus::AWAITING_INITIAL_SCREENING,
            ArticleStatus::IN_TRANSIT => LifecycleStatus::TRANSFER_REQUESTED,
            ArticleStatus::RESUBMITTED => LifecycleStatus::REVISION_SUBMITTED,
            ArticleStatus::MINOR_REVISION_REQUIRED => LifecycleStatus::MINOR_REVISION_REQUESTED,
            ArticleStatus::MAJOR_REVISION_REQUIRED, ArticleStatus::REVISION_REQUIRED => LifecycleStatus::MAJOR_REVISION_REQUESTED,
            ArticleStatus::ACCEPTED => LifecycleStatus::ACCEPTED,
            ArticleStatus::REJECTED => LifecycleStatus::REJECTED,
            ArticleStatus::COPY_EDITING => LifecycleStatus::COPYEDITING_IN_PROGRESS,
            ArticleStatus::PROOFREADING => LifecycleStatus::AWAITING_AUTHOR_PROOFREADING,
            ArticleStatus::READY_FOR_PUBLICATION => LifecycleStatus::READY_FOR_PUBLICATION,
            ArticleStatus::PUBLISHED => LifecycleStatus::PUBLISHED,
            default => LifecycleStatus::AWAITING_INITIAL_SCREENING,
        };
    }
}
