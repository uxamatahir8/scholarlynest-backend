<?php

namespace App\Constants;

final class ArticleStatus
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under_review';
    public const ASSIGNED_TO_SUB_EDITOR = 'assigned_to_sub_editor';
    public const REVIEWER_ASSIGNED = 'reviewer_assigned';
    public const REVIEW_IN_PROGRESS = 'review_in_progress';
    public const REVISION_REQUIRED = 'revision_required';
    public const MINOR_REVISION_REQUIRED = 'minor_revision_required';
    public const MAJOR_REVISION_REQUIRED = 'major_revision_required';
    public const RESUBMITTED = 'resubmitted';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const COPY_EDITING = 'copy_editing';
    public const PROOFREADING = 'proofreading';
    public const READY_FOR_PUBLICATION = 'ready_for_publication';
    public const PUBLISHED = 'published';
    public const WITHDRAWN = 'withdrawn';
    public const ARCHIVED = 'archived';

    public const ALL = [
        self::DRAFT,
        self::SUBMITTED,
        self::UNDER_REVIEW,
        self::ASSIGNED_TO_SUB_EDITOR,
        self::REVIEWER_ASSIGNED,
        self::REVIEW_IN_PROGRESS,
        self::REVISION_REQUIRED,
        self::MINOR_REVISION_REQUIRED,
        self::MAJOR_REVISION_REQUIRED,
        self::RESUBMITTED,
        self::ACCEPTED,
        self::REJECTED,
        self::COPY_EDITING,
        self::PROOFREADING,
        self::READY_FOR_PUBLICATION,
        self::PUBLISHED,
        self::WITHDRAWN,
        self::ARCHIVED,
    ];

    public const LEGACY_MAP = [
        'pending' => self::SUBMITTED,
        'approved' => self::ACCEPTED,
        'minor_review_rejected' => self::REVISION_REQUIRED,
        'fully_rejected' => self::REJECTED,
    ];

    public const TRANSITIONS = [
        self::DRAFT => [self::SUBMITTED, self::WITHDRAWN],
        self::SUBMITTED => [self::UNDER_REVIEW, self::REJECTED, self::WITHDRAWN],
        self::UNDER_REVIEW => [self::ASSIGNED_TO_SUB_EDITOR, self::REVIEWER_ASSIGNED, self::ACCEPTED, self::REJECTED],
        self::ASSIGNED_TO_SUB_EDITOR => [self::REVIEWER_ASSIGNED, self::REVISION_REQUIRED, self::ACCEPTED, self::REJECTED],
        self::REVIEWER_ASSIGNED => [self::REVIEW_IN_PROGRESS, self::REVISION_REQUIRED, self::ACCEPTED, self::REJECTED],
        self::REVIEW_IN_PROGRESS => [self::REVISION_REQUIRED, self::MINOR_REVISION_REQUIRED, self::MAJOR_REVISION_REQUIRED, self::ACCEPTED, self::REJECTED],
        self::REVISION_REQUIRED => [self::RESUBMITTED, self::WITHDRAWN],
        self::MINOR_REVISION_REQUIRED => [self::RESUBMITTED, self::WITHDRAWN],
        self::MAJOR_REVISION_REQUIRED => [self::RESUBMITTED, self::WITHDRAWN],
        self::RESUBMITTED => [self::UNDER_REVIEW, self::REVIEWER_ASSIGNED, self::ACCEPTED, self::REJECTED],
        self::ACCEPTED => [self::COPY_EDITING, self::READY_FOR_PUBLICATION, self::PUBLISHED],
        self::COPY_EDITING => [self::PROOFREADING, self::READY_FOR_PUBLICATION],
        self::PROOFREADING => [self::READY_FOR_PUBLICATION, self::COPY_EDITING],
        self::READY_FOR_PUBLICATION => [self::PUBLISHED],
        self::PUBLISHED => [self::ARCHIVED, self::WITHDRAWN],
    ];

    public const AUTHOR_VISIBLE = [
        self::DRAFT => 'Draft',
        self::SUBMITTED => 'Submitted',
        self::UNDER_REVIEW => 'Under review',
        self::ASSIGNED_TO_SUB_EDITOR => 'Under review',
        self::REVIEWER_ASSIGNED => 'Under review',
        self::REVIEW_IN_PROGRESS => 'Under review',
        self::REVISION_REQUIRED => 'Revision required',
        self::MINOR_REVISION_REQUIRED => 'Minor revision required',
        self::MAJOR_REVISION_REQUIRED => 'Major revision required',
        self::RESUBMITTED => 'Resubmitted',
        self::ACCEPTED => 'Accepted',
        self::REJECTED => 'Rejected',
        self::COPY_EDITING => 'In production',
        self::PROOFREADING => 'In production',
        self::READY_FOR_PUBLICATION => 'Ready for publication',
        self::PUBLISHED => 'Published',
        self::WITHDRAWN => 'Withdrawn',
        self::ARCHIVED => 'Archived',
    ];

    public static function normalize(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return self::LEGACY_MAP[$status] ?? $status;
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[self::normalize($from)] ?? [], true);
    }

    public static function authorCanEdit(string $status): bool
    {
        return in_array(self::normalize($status), [
            self::DRAFT,
            self::REVISION_REQUIRED,
            self::MINOR_REVISION_REQUIRED,
            self::MAJOR_REVISION_REQUIRED,
        ], true);
    }

    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::ALL);
    }
}
