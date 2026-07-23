<?php

namespace App\Observers;

use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationEventRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class WorkflowAssignmentObserver
{
    public function updated(Model $assignment): void
    {
        [$subjectType, $recipientId] = $this->identity($assignment);

        if ($assignment->wasChanged('due_date') && $assignment->due_date) {
            UserNotification::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $assignment->id)
                ->where('action_status', 'pending')
                ->update(['action_status' => 'cancelled', 'action_cancelled_at' => now(), 'updated_at' => now()]);

            $version = hash('sha256', $assignment->due_date->utc()->format('Y-m-d\TH:i:s.u\Z'));
            app(NotificationEventRecorder::class)->record(
                'deadline.changed', $assignment->article, Auth::user(),
                [
                    'assignment_id' => $assignment->id,
                    'recipient_user_id' => $recipientId,
                    'recipient_privacy_variant' => match ($subjectType) {
                        'sub_editor_assignment' => 'sub_editor',
                        'reviewer_assignment' => 'reviewer',
                        default => 'assignee',
                    },
                    'due_at' => $assignment->due_date->toISOString(),
                    'due_date_version' => $version,
                ],
                $subjectType, $assignment->id,
                deduplicationKey: "deadline-change:{$subjectType}:{$assignment->id}:{$version}"
            );
        }

        if ($assignment->wasChanged('status')) {
            $status = (string) $assignment->status;
            $action = in_array($status, ['completed', 'submitted'], true) ? 'completed'
                : (in_array($status, ['cancelled', 'declined', 'superseded'], true) ? 'cancelled' : null);
            if ($action) {
                UserNotification::query()
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $assignment->id)
                    ->where('action_status', 'pending')
                    ->update([
                        'action_status' => $action,
                        $action === 'completed' ? 'action_completed_at' : 'action_cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function identity(Model $assignment): array
    {
        return match (true) {
            $assignment instanceof SubEditorAssignment => ['sub_editor_assignment', (int) $assignment->sub_editor_id],
            $assignment instanceof ReviewerAssignment => ['reviewer_assignment', (int) $assignment->reviewer_id],
            $assignment instanceof ProductionAssignment => ['production_assignment', (int) $assignment->user_id],
        };
    }
}
