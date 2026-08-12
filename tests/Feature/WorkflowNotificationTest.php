<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Listeners\SendArticleWorkflowNotifications;
use App\Mail\GenericSystemMail;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\NotificationLog;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\SubEditorAssignment;
use App\Models\User;
use App\Models\WorkflowDeadlineReminderLog;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Magazine $magazine;

    private Article $article;

    private User $admin;

    private User $editor;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        $this->admin = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'Notification Magazine',
            'slug' => 'notification-magazine',
            'description' => 'Workflow notification magazine',
        ]);

        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);

        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Notification Article',
            'slug' => 'notification-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::ACCEPTED,
        ]);
    }

    public function test_workflow_event_sends_expected_recipients_and_audit_log(): void
    {
        Queue::fake();

        $listener = new SendArticleWorkflowNotifications(app(NotificationService::class));
        $listener->handle(new ArticleWorkflowEventOccurred(
            $this->article,
            'article.accepted',
            $this->editor,
            ['from_status' => ArticleStatus::REVIEW_IN_PROGRESS, 'to_status' => ArticleStatus::ACCEPTED]
        ));

        $this->assertDatabaseHas('notification_logs', ['recipient_email' => $this->author->email]);
        $this->assertDatabaseHas('notification_logs', ['recipient_email' => $this->editor->email]);
        $this->assertDatabaseHas('notification_logs', ['recipient_email' => $this->admin->email]);
        $this->assertSame(3, NotificationLog::count());

        $authorLog = NotificationLog::where('recipient_email', $this->author->email)->firstOrFail();
        $editorLog = NotificationLog::where('recipient_email', $this->editor->email)->firstOrFail();
        $this->assertStringContainsString('Congratulations', implode(' ', $authorLog->payload['bodyLines']));
        $this->assertStringContainsString('The manuscript', implode(' ', $editorLog->payload['bodyLines']));
        $this->assertStringNotContainsString('Your article has been accepted', implode(' ', $editorLog->payload['bodyLines']));
        $this->assertSame('View Submission Status', $authorLog->payload['action']['text']);
        $this->assertSame('Open Article Workflow', $editorLog->payload['action']['text']);

        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'notification.sent',
        ]);
    }

    public function test_sub_editor_assignment_emails_are_recipient_specific_and_use_actual_names(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::create(2026, 7, 16, 3, 19));
        $this->editor->update(['name' => 'JAm']);
        $this->author->update(['name' => 'Article Author']);
        $this->admin->update(['name' => 'Super Admin User']);
        $subEditorRole = Role::create(['name' => 'sub_editor', 'display_name' => 'Sub Editor', 'is_system' => true]);
        $subEditor = User::factory()->create(['name' => 'Usama Tahir', 'role_id' => $subEditorRole->id]);

        SubEditorAssignment::create([
            'article_id' => $this->article->id,
            'sub_editor_id' => $subEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);

        $listener = new SendArticleWorkflowNotifications(app(NotificationService::class));
        $listener->handle(new ArticleWorkflowEventOccurred(
            $this->article,
            'sub_editor.assigned',
            $this->editor,
            [
                'sub_editor' => $subEditor,
                'from_status' => ArticleStatus::UNDER_REVIEW,
                'to_status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
            ]
        ));

        $this->assertSame(4, NotificationLog::count());
        $subEditorLog = NotificationLog::where('recipient_email', $subEditor->email)->firstOrFail();
        $editorLog = NotificationLog::where('recipient_email', $this->editor->email)->firstOrFail();
        $authorLog = NotificationLog::where('recipient_email', $this->author->email)->firstOrFail();
        $adminLog = NotificationLog::where('recipient_email', $this->admin->email)->firstOrFail();

        $this->assertSame('Sub Editor Assignment: Notification Article', $subEditorLog->subject);
        $this->assertSame('Dear Usama Tahir,', $subEditorLog->payload['greeting']);
        $this->assertStringContainsString('You have been assigned as the Sub Editor', implode(' ', $subEditorLog->payload['bodyLines']));
        $this->assertStringContainsString('Assigned By:</strong> JAm', implode(' ', $subEditorLog->payload['bodyLines']));
        $this->assertStringContainsString('Sub Editor:</strong> Usama Tahir', implode(' ', $subEditorLog->payload['bodyLines']));
        $this->assertStringContainsString('16-Jul-2026 03:19', implode(' ', $subEditorLog->payload['bodyLines']));
        $this->assertSame('Open Sub Editor Workflow', $subEditorLog->payload['action']['text']);

        $this->assertSame('Sub Editor Assigned: Notification Article', $editorLog->subject);
        $this->assertSame('Dear JAm,', $editorLog->payload['greeting']);
        $this->assertStringContainsString('Usama Tahir has been assigned', implode(' ', $editorLog->payload['bodyLines']));
        $this->assertStringNotContainsString('You have been assigned as the Sub Editor', implode(' ', $editorLog->payload['bodyLines']));
        $this->assertSame('Open Article Workflow', $editorLog->payload['action']['text']);

        $this->assertSame('Editorial Update: Sub Editor Assigned to Your Manuscript', $authorLog->subject);
        $this->assertSame('Dear Article Author,', $authorLog->payload['greeting']);
        $this->assertStringContainsString('No action is required', implode(' ', $authorLog->payload['bodyLines']));
        $this->assertStringNotContainsString('You have been assigned as the Sub Editor', implode(' ', $authorLog->payload['bodyLines']));
        $this->assertSame('View Submission Status', $authorLog->payload['action']['text']);

        $this->assertSame('Workflow Update: Sub Editor Assigned', $adminLog->subject);
        $this->assertSame('Dear Super Admin User,', $adminLog->payload['greeting']);
        $this->assertStringContainsString('workflow oversight', implode(' ', $adminLog->payload['bodyLines']));
        $this->assertStringNotContainsString('You have been assigned as the Sub Editor', implode(' ', $adminLog->payload['bodyLines']));
        $this->assertSame('Open Article Workflow', $adminLog->payload['action']['text']);

        $text = view('emails.generic-text', [
            'greeting' => $subEditorLog->payload['greeting'],
            'bodyLines' => $subEditorLog->payload['bodyLines'],
            'action' => $subEditorLog->payload['action'],
        ])->render();
        $this->assertStringContainsString('Dear Usama Tahir,', $text);
        $this->assertStringContainsString('You have been assigned as the Sub Editor', $text);
        $mail = new GenericSystemMail(
            $subEditorLog->recipient_email,
            $subEditorLog->subject,
            $subEditorLog->payload['greeting'],
            $subEditorLog->payload['bodyLines'],
            $subEditorLog->payload['action']
        );
        $this->assertSame('emails.generic-text', $mail->content()->text);
        $html = $mail->render();
        $this->assertStringContainsString('Dear Usama Tahir,', $html);
        $this->assertStringContainsString('Open Sub Editor Workflow', $html);
    }

    public function test_reviewer_assignment_update_keeps_author_copy_informational_and_blind(): void
    {
        Queue::fake();
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $reviewer = User::factory()->create(['name' => 'Dr Reviewer', 'role_id' => $reviewerRole->id]);

        $listener = new SendArticleWorkflowNotifications(app(NotificationService::class));
        $listener->handle(new ArticleWorkflowEventOccurred(
            $this->article,
            'reviewer.assigned',
            $this->editor,
            [
                'reviewer' => $reviewer,
                'from_status' => ArticleStatus::UNDER_REVIEW,
                'to_status' => ArticleStatus::REVIEWER_ASSIGNED,
            ]
        ));

        $authorLog = NotificationLog::where('recipient_email', $this->author->email)->firstOrFail();
        $this->assertDatabaseMissing('notification_logs', ['recipient_email' => $reviewer->email]);
        $this->assertStringContainsString('A reviewer has been invited', implode(' ', $authorLog->payload['bodyLines']));
        $this->assertStringNotContainsString('Dr Reviewer', implode(' ', $authorLog->payload['bodyLines']));
        $this->assertStringNotContainsString($reviewer->email, implode(' ', $authorLog->payload['bodyLines']));
    }

    public function test_workflow_notification_is_not_duplicated_for_same_transition(): void
    {
        Queue::fake();

        $listener = new SendArticleWorkflowNotifications(app(NotificationService::class));
        $event = new ArticleWorkflowEventOccurred(
            $this->article,
            'article.rejected',
            $this->editor,
            ['from_status' => ArticleStatus::REVIEW_IN_PROGRESS, 'to_status' => ArticleStatus::REJECTED]
        );

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(3, NotificationLog::count());
        $this->assertSame(1, $this->article->auditLogs()
            ->where('event', 'notification.sent')
            ->where('from_status', ArticleStatus::REVIEW_IN_PROGRESS)
            ->where('to_status', ArticleStatus::REJECTED)
            ->count());
    }

    public function test_deadline_reminder_command_sends_due_windows_once(): void
    {
        Queue::fake();

        $subEditor = User::factory()->create(['role_id' => Role::create(['name' => 'sub_editor', 'display_name' => 'Sub Editor'])->id]);
        $reviewer = User::factory()->create(['role_id' => Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer'])->id]);
        $copyEditor = User::factory()->create(['role_id' => Role::create(['name' => 'copy_editor', 'display_name' => 'Copy Editor'])->id]);

        SubEditorAssignment::create([
            'article_id' => $this->article->id,
            'sub_editor_id' => $subEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        ReviewerAssignment::create([
            'article_id' => $this->article->id,
            'reviewer_id' => $reviewer->id,
            'assigned_by' => $this->editor->id,
            'status' => 'accepted',
            'due_date' => now(),
        ]);

        ProductionAssignment::create([
            'article_id' => $this->article->id,
            'user_id' => $copyEditor->id,
            'assigned_by' => $this->editor->id,
            'role' => 'copy_editor',
            'status' => 'pending',
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan('workflow:send-deadline-reminders')->assertExitCode(0);

        $this->assertSame(4, WorkflowDeadlineReminderLog::count());
        $this->assertDatabaseHas('workflow_deadline_reminder_logs', ['reminder_type' => 'due_in_3_days']);
        $this->assertDatabaseHas('workflow_deadline_reminder_logs', ['reminder_type' => 'due_today']);
        $this->assertDatabaseHas('workflow_deadline_reminder_logs', ['reminder_type' => 'overdue_3_days']);

        $this->artisan('workflow:send-deadline-reminders')->assertExitCode(0);

        $this->assertSame(4, WorkflowDeadlineReminderLog::count());
        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'deadline.overdue_reminder.recorded',
        ]);
        $this->assertDatabaseHas('workflow_deadline_reminder_logs', [
            'recipient_user_id' => $this->editor->id,
            'reminder_type' => 'overdue_3_days',
            'escalated_to_user_id' => $this->editor->id,
        ]);
    }
}
