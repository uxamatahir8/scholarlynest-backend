<?php

namespace App\Console\Commands;

use App\Models\ArticleAuditLog;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\User;
use App\Models\WorkflowDeadlineReminderLog;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class SendWorkflowDeadlineReminders extends Command
{
    protected $signature = 'workflow:send-deadline-reminders';

    protected $description = 'Send workflow assignment deadline reminders without duplicating reminder windows.';

    private int $sent = 0;

    public function __construct(private NotificationService $notificationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->processAssignments(
            SubEditorAssignment::with(['article.magazine', 'subEditor'])
                ->where('status', 'pending')
                ->whereNotNull('due_date')
                ->get(),
            'sub_editor_assignment',
            fn (SubEditorAssignment $assignment) => $assignment->subEditor,
            'Sub Editor'
        );

        $this->processAssignments(
            ReviewerAssignment::with(['article.magazine', 'reviewer'])
                ->whereIn('status', ['pending', 'accepted'])
                ->whereNotNull('due_date')
                ->get(),
            'reviewer_assignment',
            fn (ReviewerAssignment $assignment) => $assignment->reviewer,
            'Reviewer'
        );

        $this->processAssignments(
            ProductionAssignment::with(['article.magazine', 'user'])
                ->where('status', 'pending')
                ->whereNotNull('due_date')
                ->get(),
            'production_assignment',
            fn (ProductionAssignment $assignment) => $assignment->user,
            'Production'
        );

        $this->info("Workflow deadline reminders sent: {$this->sent}");

        return self::SUCCESS;
    }

    private function processAssignments(EloquentCollection $assignments, string $assignmentType, callable $recipientResolver, string $label): void
    {
        foreach ($assignments as $assignment) {
            $reminderType = $this->reminderType($assignment->due_date);
            if (!$reminderType) {
                continue;
            }

            if ($this->alreadySent($assignmentType, $assignment->id, $reminderType)) {
                continue;
            }

            $recipient = $recipientResolver($assignment);
            if (!$recipient instanceof User || !$recipient->email) {
                continue;
            }

            $article = $assignment->article;
            $subject = $this->subjectFor($reminderType, $label, $article->title);
            $body = [
                $this->bodyLineFor($reminderType, $label, $article->title),
                'Magazine: ' . ($article->magazine?->title ?? 'ScholarlyNest'),
                'Due date: ' . $assignment->due_date->toFormattedDateString(),
            ];

            $this->notificationService->send(
                $recipient->email,
                $subject,
                'Dear ' . $recipient->name . ',',
                $body,
                [
                    'text' => 'Open Workflow',
                    'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . '/admin/articles',
                ],
                'default',
                $recipient->id
            );

            WorkflowDeadlineReminderLog::create([
                'article_id' => $article->id,
                'assignment_type' => $assignmentType,
                'assignment_id' => $assignment->id,
                'reminder_type' => $reminderType,
                'due_date' => $assignment->due_date,
                'sent_at' => now(),
            ]);

            ArticleAuditLog::create([
                'article_id' => $article->id,
                'actor_id' => null,
                'event' => $reminderType === 'overdue_3_days' ? 'deadline.overdue_reminder.sent' : 'deadline.reminder.sent',
                'from_status' => $article->status,
                'to_status' => $article->status,
                'payload' => [
                    'assignment_type' => $assignmentType,
                    'assignment_id' => $assignment->id,
                    'reminder_type' => $reminderType,
                    'recipient_email' => $recipient->email,
                ],
            ]);

            $this->sent++;
        }
    }

    private function reminderType($dueDate): ?string
    {
        $daysUntilDue = (int) now()->startOfDay()->diffInDays($dueDate->copy()->startOfDay(), false);

        return match ($daysUntilDue) {
            3 => 'due_in_3_days',
            0 => 'due_today',
            -3 => 'overdue_3_days',
            default => null,
        };
    }

    private function alreadySent(string $assignmentType, int $assignmentId, string $reminderType): bool
    {
        return WorkflowDeadlineReminderLog::query()
            ->where('assignment_type', $assignmentType)
            ->where('assignment_id', $assignmentId)
            ->where('reminder_type', $reminderType)
            ->exists();
    }

    private function subjectFor(string $reminderType, string $label, string $articleTitle): string
    {
        return match ($reminderType) {
            'due_in_3_days' => "{$label} Deadline Approaching: {$articleTitle}",
            'due_today' => "{$label} Deadline Today: {$articleTitle}",
            'overdue_3_days' => "{$label} Deadline Overdue: {$articleTitle}",
            default => "{$label} Deadline Reminder: {$articleTitle}",
        };
    }

    private function bodyLineFor(string $reminderType, string $label, string $articleTitle): string
    {
        return match ($reminderType) {
            'due_in_3_days' => "Your {$label} assignment for \"{$articleTitle}\" is due in 3 days.",
            'due_today' => "Your {$label} assignment for \"{$articleTitle}\" is due today.",
            'overdue_3_days' => "Your {$label} assignment for \"{$articleTitle}\" is now 3 days overdue.",
            default => "Your {$label} assignment for \"{$articleTitle}\" has a deadline update.",
        };
    }
}
