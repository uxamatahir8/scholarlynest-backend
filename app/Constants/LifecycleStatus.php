<?php

namespace App\Constants;

final class LifecycleStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const AWAITING_INITIAL_SCREENING = 'awaiting_initial_screening';

    public const TRANSFER_REQUESTED = 'transfer_requested';

    public const DESK_REJECTED = 'desk_rejected';

    public const SCREENING_COMPLETED = 'screening_completed';

    public const AWAITING_SUB_EDITOR_ASSIGNMENT = 'awaiting_sub_editor_assignment';

    public const AWAITING_REVIEWER_INVITATION = 'awaiting_reviewer_invitation';

    public const REVIEWER_INVITATIONS_PENDING = 'reviewer_invitations_pending';

    public const UNDER_REVIEW = 'under_review';

    public const REVIEWS_PARTIALLY_COMPLETED = 'reviews_partially_completed';

    public const REVIEWS_COMPLETED = 'reviews_completed';

    public const AWAITING_SUB_EDITOR_RECOMMENDATION = 'awaiting_sub_editor_recommendation';

    public const AWAITING_EDITORIAL_DECISION = 'awaiting_editorial_decision';

    public const MINOR_REVISION_REQUESTED = 'minor_revision_requested';

    public const MAJOR_REVISION_REQUESTED = 'major_revision_requested';

    public const AWAITING_AUTHOR_REVISION = 'awaiting_author_revision';

    public const REVISION_SUBMITTED = 'revision_submitted';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const AWAITING_COPY_EDITOR_ASSIGNMENT = 'awaiting_copy_editor_assignment';

    public const COPYEDITING_IN_PROGRESS = 'copyediting_in_progress';

    public const AWAITING_AUTHOR_PROOFREADING = 'awaiting_author_proofreading';

    public const PROOF_CORRECTIONS_IN_PROGRESS = 'proof_corrections_in_progress';

    public const PROOF_APPROVED = 'proof_approved';

    public const READY_FOR_PUBLICATION = 'ready_for_publication';

    public const AWAITING_ISSUE_ASSIGNMENT = 'awaiting_issue_assignment';

    public const ASSIGNED_TO_ISSUE = 'assigned_to_issue';

    public const SCHEDULED_FOR_PUBLICATION = 'scheduled_for_publication';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    public const ALL = [
        self::DRAFT, self::SUBMITTED, self::AWAITING_INITIAL_SCREENING,
        self::TRANSFER_REQUESTED, self::DESK_REJECTED, self::SCREENING_COMPLETED,
        self::AWAITING_SUB_EDITOR_ASSIGNMENT, self::AWAITING_REVIEWER_INVITATION,
        self::REVIEWER_INVITATIONS_PENDING, self::UNDER_REVIEW,
        self::REVIEWS_PARTIALLY_COMPLETED, self::REVIEWS_COMPLETED,
        self::AWAITING_SUB_EDITOR_RECOMMENDATION, self::AWAITING_EDITORIAL_DECISION,
        self::MINOR_REVISION_REQUESTED, self::MAJOR_REVISION_REQUESTED,
        self::AWAITING_AUTHOR_REVISION, self::REVISION_SUBMITTED, self::ACCEPTED,
        self::REJECTED, self::AWAITING_COPY_EDITOR_ASSIGNMENT,
        self::COPYEDITING_IN_PROGRESS, self::AWAITING_AUTHOR_PROOFREADING,
        self::PROOF_CORRECTIONS_IN_PROGRESS, self::PROOF_APPROVED,
        self::READY_FOR_PUBLICATION, self::AWAITING_ISSUE_ASSIGNMENT,
        self::ASSIGNED_TO_ISSUE, self::SCHEDULED_FOR_PUBLICATION,
        self::PUBLISHED, self::UNPUBLISHED,
    ];

    public const TERMINAL = [self::DESK_REJECTED, self::REJECTED];

    public const LABELS = [
        self::DRAFT => 'Draft', self::SUBMITTED => 'Submitted',
        self::AWAITING_INITIAL_SCREENING => 'Awaiting Initial Screening',
        self::TRANSFER_REQUESTED => 'Transfer Requested', self::DESK_REJECTED => 'Desk Rejected',
        self::SCREENING_COMPLETED => 'Screening Completed',
        self::AWAITING_SUB_EDITOR_ASSIGNMENT => 'Awaiting Sub-editor Assignment',
        self::AWAITING_REVIEWER_INVITATION => 'Awaiting Reviewer Invitation',
        self::REVIEWER_INVITATIONS_PENDING => 'Reviewer Invitations Pending',
        self::UNDER_REVIEW => 'Under Review', self::REVIEWS_PARTIALLY_COMPLETED => 'Reviews Partially Completed',
        self::REVIEWS_COMPLETED => 'Reviews Completed',
        self::AWAITING_SUB_EDITOR_RECOMMENDATION => 'Awaiting Sub-editor Recommendation',
        self::AWAITING_EDITORIAL_DECISION => 'Awaiting Editorial Decision',
        self::MINOR_REVISION_REQUESTED => 'Minor Revision Requested',
        self::MAJOR_REVISION_REQUESTED => 'Major Revision Requested',
        self::AWAITING_AUTHOR_REVISION => 'Awaiting Author Revision',
        self::REVISION_SUBMITTED => 'Revision Submitted', self::ACCEPTED => 'Accepted',
        self::REJECTED => 'Rejected', self::AWAITING_COPY_EDITOR_ASSIGNMENT => 'Awaiting Copy Editor Assignment',
        self::COPYEDITING_IN_PROGRESS => 'Copyediting In Progress',
        self::AWAITING_AUTHOR_PROOFREADING => 'Awaiting Author Proofreading',
        self::PROOF_CORRECTIONS_IN_PROGRESS => 'Proof Corrections In Progress',
        self::PROOF_APPROVED => 'Proof Approved', self::READY_FOR_PUBLICATION => 'Ready for Publication',
        self::AWAITING_ISSUE_ASSIGNMENT => 'Awaiting Issue Assignment',
        self::ASSIGNED_TO_ISSUE => 'Assigned to Issue',
        self::SCHEDULED_FOR_PUBLICATION => 'Scheduled for Publication',
        self::PUBLISHED => 'Published', self::UNPUBLISHED => 'Unpublished',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? str($status)->replace('_', ' ')->title()->toString();
    }
}
