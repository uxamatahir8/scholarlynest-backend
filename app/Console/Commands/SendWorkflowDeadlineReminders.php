<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\User;
use App\Models\WorkflowDeadlineReminderLog;
use App\Services\Notifications\NotificationEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SendWorkflowDeadlineReminders extends Command
{
    protected $signature = 'workflow:send-deadline-reminders {--chunk=200}';

    protected $description = 'Record retry-safe workflow deadline and invitation-expiry notifications.';

    private int $recorded = 0;

    public function __construct(private NotificationEventRecorder $events)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('notification_system.features.enabled', true) || ! config('notification_system.features.reminders', true)) {
            $this->info('Workflow reminder notifications are disabled.');

            return self::SUCCESS;
        }
        $this->processAssignmentType(SubEditorAssignment::class, 'sub_editor_assignment', 'subEditor', 'sub_editor_id', ['pending']);
        $this->processAssignmentType(ReviewerAssignment::class, 'reviewer_assignment', 'reviewer', 'reviewer_id', ['pending', 'accepted', 'review_in_progress', 'reopened']);
        $this->processAssignmentType(ProductionAssignment::class, 'production_assignment', 'user', 'user_id', ['pending', 'in_progress']);
        $this->processExpiredInvitations();

        $this->info("Workflow reminder notification events recorded: {$this->recorded}");

        return self::SUCCESS;
    }

    private function processAssignmentType(string $modelClass, string $assignmentType, string $recipientRelation, string $recipientColumn, array $statuses): void
    {
        foreach ($this->windows() as $reminderType => $date) {
            $modelClass::query()
                ->with(['article.magazine', $recipientRelation, 'assigner'])
                ->whereIn('status', $statuses)
                ->whereBetween('due_date', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
                ->chunkById($this->chunkSize(), function ($assignments) use ($assignmentType, $recipientColumn, $statuses, $reminderType) {
                    foreach ($assignments as $assignment) {
                        $fresh = $assignment::query()->with(['article.magazine', 'assigner'])->find($assignment->id);
                        if (! $fresh || ! in_array($fresh->status, $statuses, true) || ! $fresh->due_date) {
                            continue;
                        }
                        $recipient = User::find($fresh->{$recipientColumn});
                        if (! $recipient) {
                            continue;
                        }
                        $recipientIds = collect([$recipient->id]);
                        if ($reminderType === 'overdue_3_days' && $fresh->assigned_by) {
                            $recipientIds->push((int) $fresh->assigned_by);
                        }
                        $recipientIds->unique()->each(fn (int $recipientId) => $this->recordAssignmentReminder(
                            $fresh, $assignmentType, $reminderType, $recipientId, $recipientId !== (int) $recipient->id
                        ));
                    }
                });
        }
    }

    private function recordAssignmentReminder(Model $assignment, string $assignmentType, string $reminderType, int $recipientId, bool $escalation): void
    {
        $dueVersion = hash('sha256', $assignment->due_date->utc()->format('Y-m-d\TH:i:s.u\Z'));
        DB::transaction(function () use ($assignment, $assignmentType, $reminderType, $recipientId, $escalation, $dueVersion) {
            $log = WorkflowDeadlineReminderLog::firstOrCreate(
                [
                    'assignment_type' => $assignmentType,
                    'assignment_id' => $assignment->id,
                    'recipient_user_id' => $recipientId,
                    'reminder_type' => $reminderType,
                    'due_date_version' => $dueVersion,
                ],
                [
                    'article_id' => $assignment->article_id,
                    'due_date' => $assignment->due_date,
                    'sent_at' => now(),
                    'escalated_to_user_id' => $escalation ? $recipientId : null,
                    'delivery_status' => 'recorded',
                ]
            );
            if (! $log->wasRecentlyCreated) {
                return;
            }

            $eventType = "deadline.{$reminderType}";
            $subjectType = match ($assignmentType) {
                'sub_editor_assignment' => 'sub_editor_assignment',
                'reviewer_assignment' => 'reviewer_assignment',
                default => 'production_assignment',
            };
            $event = $this->events->record(
                $eventType,
                $assignment->article,
                null,
                [
                    'assignment_id' => $assignment->id,
                    'recipient_user_id' => $recipientId,
                    'recipient_privacy_variant' => $escalation ? 'editor' : match ($assignmentType) {
                        'sub_editor_assignment' => 'sub_editor',
                        'reviewer_assignment' => 'reviewer',
                        default => 'assignee',
                    },
                    'due_at' => $assignment->due_date->toISOString(),
                    'due_date_version' => $dueVersion,
                    'reminder_type' => $reminderType,
                    'is_escalation' => $escalation,
                ],
                $subjectType,
                $assignment->id,
                deduplicationKey: "reminder:{$assignmentType}:{$assignment->id}:{$recipientId}:{$reminderType}:{$dueVersion}"
            );
            $log->update(['notification_event_id' => $event?->id]);
            $this->audit($assignment->article, $assignmentType, $assignment->id, $reminderType, $recipientId, $dueVersion);
            $this->recorded++;
        });
    }

    private function processExpiredInvitations(): void
    {
        ReviewerAssignment::query()
            ->with(['article.magazine', 'reviewer'])
            ->where('status', 'pending')
            ->whereNotNull('invite_expires_at')
            ->where('invite_expires_at', '<=', now())
            ->chunkById($this->chunkSize(), function ($assignments) {
                foreach ($assignments as $assignment) {
                    $key = "review-invitation:{$assignment->id}:expired:{$assignment->invite_expires_at->utc()->timestamp}";
                    $event = $this->events->record(
                        'review.invitation_expired', $assignment->article, null,
                        ['assignment_id' => $assignment->id, 'due_at' => $assignment->invite_expires_at->toISOString()],
                        'reviewer_assignment', $assignment->id, deduplicationKey: $key
                    );
                    if ($event?->wasRecentlyCreated) {
                        $assignment->update(['status' => 'expired']);
                        $this->recorded++;
                    }
                }
            });
    }

    private function windows(): array
    {
        return ['due_in_3_days' => now()->addDays(3), 'due_today' => now(), 'overdue_3_days' => now()->subDays(3)];
    }

    private function chunkSize(): int
    {
        return max(25, min(1000, (int) $this->option('chunk')));
    }

    private function audit(Article $article, string $assignmentType, int $assignmentId, string $reminderType, int $recipientId, string $dueVersion): void
    {
        ArticleAuditLog::create([
            'article_id' => $article->id,
            'actor_id' => null,
            'event' => $reminderType === 'overdue_3_days' ? 'deadline.overdue_reminder.recorded' : 'deadline.reminder.recorded',
            'from_status' => $article->status,
            'to_status' => $article->status,
            'payload' => compact('assignmentType', 'assignmentId', 'reminderType', 'recipientId', 'dueVersion'),
        ]);
    }
}
