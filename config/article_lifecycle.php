<?php

return [
    'enabled' => env('ARTICLE_LIFECYCLE_ENABLED', true),
    'workspace_enabled' => env('ARTICLE_LIFECYCLE_WORKSPACE_ENABLED', true),
    'strict_version_scope' => env('ARTICLE_LIFECYCLE_STRICT_VERSION_SCOPE', true),
    'review_invitation_days' => (int) env('ARTICLE_REVIEW_INVITATION_DAYS', 14),
    'proof_response_days' => (int) env('ARTICLE_PROOF_RESPONSE_DAYS', 14),
    'retention_days' => (int) env('ARTICLE_LIFECYCLE_RETENTION_DAYS', 2555),

    // Canonical command map. Services enforce the command-specific version,
    // assignment, file and actor requirements after this shared state gate.
    'transitions' => [
        'screen' => ['from' => ['submitted', 'awaiting_initial_screening', 'revision_submitted']],
        'assign-sub-editor' => ['from' => ['screening_completed', 'awaiting_sub_editor_assignment', 'awaiting_reviewer_invitation']],
        'sub-editor-recommendation' => ['from' => ['reviews_completed', 'awaiting_sub_editor_recommendation', 'under_review', 'reviews_partially_completed']],
        'invite-reviewer' => ['from' => ['screening_completed', 'awaiting_sub_editor_assignment', 'awaiting_reviewer_invitation', 'reviewer_invitations_pending', 'under_review', 'reviews_partially_completed']],
        'accept-review' => ['from' => ['reviewer_invitations_pending', 'under_review']],
        'decline-review' => ['from' => ['reviewer_invitations_pending', 'under_review']],
        'submit-review' => ['from' => ['under_review', 'reviews_partially_completed']],
        'reopen-review' => ['from' => ['reviews_completed', 'awaiting_sub_editor_recommendation', 'awaiting_editorial_decision']],
        'editorial-decision' => ['from' => ['awaiting_reviewer_invitation', 'reviewer_invitations_pending', 'under_review', 'reviews_partially_completed', 'reviews_completed', 'awaiting_sub_editor_recommendation', 'awaiting_editorial_decision']],
        'assign-copy-editor' => ['from' => ['accepted', 'awaiting_copy_editor_assignment']],
        'complete-copyediting' => ['from' => ['copyediting_in_progress']],
        'request-proof' => ['from' => ['awaiting_author_proofreading']],
        'proof-author-response' => ['from' => ['awaiting_author_proofreading']],
        'proof-correction' => ['from' => ['proof_corrections_in_progress']],
        'prepare-publication' => ['from' => ['proof_approved', 'ready_for_publication', 'awaiting_issue_assignment']],
        'select-publication-files' => ['from' => ['awaiting_issue_assignment', 'assigned_to_issue', 'scheduled_for_publication']],
        'publish-article' => ['from' => ['assigned_to_issue', 'scheduled_for_publication']],
        'unpublish-article' => ['from' => ['published']],
    ],
];
