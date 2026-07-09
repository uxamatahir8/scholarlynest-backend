<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Listeners\SendArticleWorkflowNotifications;
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

        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'notification.sent',
        ]);
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

        $this->assertSame(3, WorkflowDeadlineReminderLog::count());
        $this->assertDatabaseHas('workflow_deadline_reminder_logs', ['reminder_type' => 'due_in_3_days']);
        $this->assertDatabaseHas('workflow_deadline_reminder_logs', ['reminder_type' => 'due_today']);
        $this->assertDatabaseHas('workflow_deadline_reminder_logs', ['reminder_type' => 'overdue_3_days']);

        $this->artisan('workflow:send-deadline-reminders')->assertExitCode(0);

        $this->assertSame(3, WorkflowDeadlineReminderLog::count());
        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'deadline.overdue_reminder.sent',
        ]);
    }
}
