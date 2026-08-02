<?php

namespace App\Services;

use App\Models\ReviewQuestionnaireInstance;
use App\Models\ReviewQuestionnaireVersion;
use App\Models\ReviewerAssignment;

class ReviewerQuestionnaireService
{
    public const ACCESSIBLE_STATUSES = ['accepted', 'in_progress', 'review_in_progress', 'reopened'];

    public function ensure(ReviewerAssignment $assignment): ?ReviewQuestionnaireInstance
    {
        $existing = ReviewQuestionnaireInstance::where('reviewer_assignment_id', $assignment->id)->first();
        if ($existing && $assignment->status === 'completed') {
            return $existing;
        }
        if (! in_array($assignment->status, self::ACCESSIBLE_STATUSES, true)) {
            return null;
        }

        $version = ReviewQuestionnaireVersion::query()
            ->where('is_active', true)
            ->with('questions.options')
            ->latest('published_at')
            ->latest('id')
            ->first();
        if (! $version) {
            return null;
        }

        $instance = ReviewQuestionnaireInstance::firstOrCreate(
            ['reviewer_assignment_id' => $assignment->id],
            [
                'article_id' => $assignment->article_id,
                'reviewer_id' => $assignment->reviewer_id,
                'review_questionnaire_version_id' => $version->id,
            ]
        );
        if (! $instance->submitted_at && $instance->review_questionnaire_version_id !== $version->id) {
            $instance->update(['review_questionnaire_version_id' => $version->id]);
        }
        if ((int) $assignment->questionnaire_instance_id !== (int) $instance->id) {
            $assignment->update(['questionnaire_instance_id' => $instance->id]);
        }

        return $instance->fresh();
    }

    public function canAccess(ReviewerAssignment $assignment): bool
    {
        return ! $assignment->revoked_at
            && ! $assignment->closed_at
            && in_array($assignment->status, self::ACCESSIBLE_STATUSES, true);
    }
}
