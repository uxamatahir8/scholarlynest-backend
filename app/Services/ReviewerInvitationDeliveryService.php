<?php

namespace App\Services;

use App\Models\ReviewerAssignment;

class ReviewerInvitationDeliveryService
{
    public function send(int $assignmentId, string $rawToken): void
    {
        $assignment = ReviewerAssignment::query()->with('article.magazine')->find($assignmentId);
        if (! $assignment || ! $assignment->invitee_email || $assignment->revoked_at || $assignment->declined_at) {
            return;
        }

        $article = $assignment->article;
        $url = rtrim((string) env('APP_URL_FRONTEND', 'http://localhost:3000'), '/')
            ."/review-invitations/{$assignment->id}?token={$rawToken}";

        app(NotificationService::class)->sendSensitive(
            $assignment->invitee_email,
            'Review Invitation — '.($article?->magazine?->title ?? 'ScholarlyNest'),
            'Dear '.($assignment->invitee_name ?: 'Reviewer').',',
            [
                'You have been invited to independently review a manuscript.',
                'Title: '.($article?->title ?? 'Untitled manuscript'),
                'Tracking code: '.($article?->tracking_code ?? 'Not assigned'),
                'Author identity is withheld under the review policy.',
                'Accepting the invitation grants access only to the assigned manuscript version.',
            ],
            ['text' => 'Accept or decline invitation', 'url' => $url],
            userId: $assignment->reviewer_id,
        );
    }
}
