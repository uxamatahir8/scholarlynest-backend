<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Listeners\SendArticleWorkflowNotifications;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationEventProjector;
use App\Services\Notifications\NotificationEventRecorder;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $editor;

    private User $other;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'magazine_editor', 'display_name' => 'Magazine Editor', 'is_system' => true]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
        $this->other = User::factory()->create(['role_id' => $authorRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $magazine = Magazine::create([
            'title' => 'Notification Journal', 'slug' => 'notification-journal',
            'description' => 'Test publication', 'publication_type' => Magazine::TYPE_MAGAZINE,
        ]);
        $this->editor->magazines()->attach($magazine->id, ['role' => 'editor']);
        $this->article = Article::create([
            'magazine_id' => $magazine->id, 'user_id' => $this->author->id,
            'title' => 'Private Review Article', 'slug' => 'private-review-article',
            'tracking_code' => 'SN-NOTIFY-001',
            'abstract' => 'Safe abstract', 'full_text' => 'Private manuscript',
            'status' => ArticleStatus::REVIEWER_ASSIGNED,
        ]);
    }

    public function test_transaction_rollback_creates_no_outbox_event(): void
    {
        try {
            DB::transaction(function () {
                app(NotificationEventRecorder::class)->record('article.under_review', $this->article, $this->editor);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('notification_events', 0);
    }

    public function test_projection_is_idempotent_and_repeated_legitimate_events_remain_distinct(): void
    {
        $recorder = app(NotificationEventRecorder::class);
        $first = $recorder->record('article.under_review', $this->article, $this->editor, deduplicationKey: 'request-123');
        $same = $recorder->record('article.under_review', $this->article, $this->editor, deduplicationKey: 'request-123');
        $second = $recorder->record('article.under_review', $this->article, $this->editor, deduplicationKey: 'request-456');

        $this->assertSame($first->id, $same->id);
        $this->assertNotSame($first->id, $second->id);

        $projector = app(NotificationEventProjector::class);
        $projector->project($first->id);
        $projector->project($first->id);
        $projector->project($second->id);

        $this->assertSame(0, UserNotification::where('recipient_user_id', $this->editor->id)->count());
        $this->assertSame(2, UserNotification::where('recipient_user_id', $this->author->id)->count());
    }

    public function test_author_variant_excludes_reviewer_identity_tokens_and_storage_data(): void
    {
        $event = app(NotificationEventRecorder::class)->record(
            'reviewer.assigned',
            $this->article,
            $this->editor,
            [
                'reviewer_id' => 999,
                'reviewer_name' => 'Hidden Reviewer',
                'reviewer_email' => 'hidden@example.test',
                'invite_token' => 'secret-token',
                's3_key' => 'private/manuscript.pdf',
                'from_status' => ArticleStatus::UNDER_REVIEW,
                'to_status' => ArticleStatus::REVIEWER_ASSIGNED,
            ]
        );
        app(NotificationEventProjector::class)->project($event->id);

        $this->assertSame([
            'from_status' => ArticleStatus::UNDER_REVIEW,
            'to_status' => ArticleStatus::REVIEWER_ASSIGNED,
        ], $event->fresh()->payload);

        $response = $this->actingAs($this->author)->getJson('/api/notifications');
        $response->assertOk();
        $json = $response->getContent();
        $this->assertStringNotContainsString('Hidden Reviewer', $json);
        $this->assertStringNotContainsString('hidden@example.test', $json);
        $this->assertStringNotContainsString('secret-token', $json);
        $this->assertStringNotContainsString('private/manuscript.pdf', $json);
        $this->assertStringNotContainsString('reviewer_id', $json);
    }

    public function test_recipient_scope_lifecycle_and_snapshot_bulk_read_are_independent(): void
    {
        $event = app(NotificationEventRecorder::class)->record('revision.requested', $this->article, $this->editor);
        app(NotificationEventProjector::class)->project($event->id);
        $notification = UserNotification::where('recipient_user_id', $this->author->id)->firstOrFail();

        $this->actingAs($this->other)->getJson("/api/notifications/{$notification->id}")->assertForbidden();
        $this->actingAs($this->author)->patchJson("/api/notifications/{$notification->id}/visibility", ['state' => 'archived'])
            ->assertOk()->assertJsonPath('data.action.status', 'pending');
        $this->assertSame('pending', $notification->fresh()->action_status);

        $notification->update(['archived_at' => null, 'created_at' => now()->subMinute()]);
        $snapshot = now();
        $laterEvent = app(NotificationEventRecorder::class)->record('article.under_review', $this->article, $this->editor);
        app(NotificationEventProjector::class)->project($laterEvent->id);
        UserNotification::where('notification_event_id', $laterEvent->id)->update(['created_at' => now()->addSecond()]);

        $this->actingAs($this->author)->postJson('/api/notifications/read-all', [
            'before' => $snapshot->toISOString(), 'scope' => ['tab' => 'unread', 'category' => null],
        ])->assertOk()->assertJsonPath('data.updated', 1);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull(UserNotification::where('notification_event_id', $laterEvent->id)->where('recipient_user_id', $this->author->id)->firstOrFail()->read_at);
    }

    public function test_mandatory_event_policy_overrides_optional_category_preference(): void
    {
        $response = $this->actingAs($this->author)->putJson('/api/notification-preferences', [
            'timezone' => 'UTC',
            'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
            'preferences' => [[
                'category' => 'security', 'in_app_enabled' => false, 'email_mode' => 'off',
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.category', 'security')
            ->assertJsonPath('data.0.in_app.enabled', false)
            ->assertJsonPath('data.0.email.mode', 'off');
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->author->id, 'category' => 'security',
            'in_app_enabled' => false, 'email_mode' => 'off',
        ]);

        $event = app(NotificationEventRecorder::class)->record(
            'account.email_changed', null, $this->author,
            ['recipient_user_id' => $this->author->id, 'recipient_privacy_variant' => 'account'],
            'user', $this->author->id, deduplicationKey: 'security-policy-test'
        );
        app(NotificationEventProjector::class)->project($event->id);

        $this->assertDatabaseHas('user_notifications', [
            'notification_event_id' => $event->id,
            'recipient_user_id' => $this->author->id,
            'privacy_variant' => 'account',
            'in_app_visible' => true,
            'email_mode' => 'immediate',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'notification_event_id' => $event->id,
            'user_id' => $this->author->id,
            'purpose' => 'account.email_changed',
        ]);

        $optional = app(NotificationEventRecorder::class)->record(
            'account.impersonation_stopped', null, $this->author,
            ['recipient_user_id' => $this->author->id, 'recipient_privacy_variant' => 'account'],
            'user', $this->author->id, deduplicationKey: 'optional-security-policy-test'
        );
        app(NotificationEventProjector::class)->project($optional->id);
        $this->assertDatabaseMissing('user_notifications', ['notification_event_id' => $optional->id]);
    }

    public function test_digest_preferences_queue_one_idempotent_digest_and_mark_items(): void
    {
        ProductionAssignment::create([
            'article_id' => $this->article->id,
            'user_id' => $this->editor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->author->id,
            'status' => 'in_progress',
        ]);
        $event = app(NotificationEventRecorder::class)->record('article_file.available', $this->article, $this->author);
        app(NotificationEventProjector::class)->project($event->id);

        $notification = UserNotification::where('recipient_user_id', $this->editor->id)->firstOrFail();
        $this->assertSame('digest', $notification->email_mode);
        $this->assertSame('daily', $notification->digest_frequency);

        Artisan::call('notifications:send-digests', ['--force' => true]);
        Artisan::call('notifications:send-digests', ['--force' => true]);

        $this->assertDatabaseCount('notification_logs', 1);
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->editor->id,
            'purpose' => 'notification.digest.daily',
            'status' => 'queued',
        ]);
        $this->assertNotNull($notification->fresh()->digest_sent_at);
    }

    public function test_optional_legacy_email_consults_the_same_event_channel_policy(): void
    {
        $this->actingAs($this->author)->putJson('/api/notification-preferences', [
            'timezone' => 'UTC',
            'preferences' => [['category' => 'editorial', 'in_app_enabled' => true, 'email_mode' => 'off']],
        ])->assertOk();

        $listener = new SendArticleWorkflowNotifications(app(NotificationService::class));
        $listener->handle(new ArticleWorkflowEventOccurred(
            $this->article, 'article.under_review', $this->editor,
            ['from_status' => ArticleStatus::SUBMITTED, 'to_status' => ArticleStatus::UNDER_REVIEW]
        ));

        $this->assertDatabaseMissing('notification_logs', [
            'recipient_email' => $this->author->email,
            'purpose' => 'article.under_review',
        ]);
    }

    public function test_impersonation_events_never_queue_email_but_remain_visible_in_app(): void
    {
        foreach (['account.impersonation_started', 'account.impersonation_stopped'] as $index => $eventType) {
            $event = app(NotificationEventRecorder::class)->record(
                $eventType,
                null,
                $this->editor,
                ['recipient_user_id' => $this->author->id, 'recipient_privacy_variant' => 'account'],
                'user',
                $this->author->id,
                deduplicationKey: "impersonation-email-disabled-{$index}"
            );

            app(NotificationEventProjector::class)->project($event->id);

            $this->assertDatabaseHas('user_notifications', [
                'notification_event_id' => $event->id,
                'recipient_user_id' => $this->author->id,
                'in_app_visible' => true,
                'email_mode' => 'off',
            ]);
            $this->assertDatabaseMissing('notification_logs', [
                'notification_event_id' => $event->id,
                'purpose' => $eventType,
            ]);
        }
    }

    public function test_feed_filters_cursor_dismiss_and_restore(): void
    {
        foreach (['revision.requested', 'article.under_review', 'article.version_created'] as $index => $type) {
            $event = app(NotificationEventRecorder::class)->record($type, $this->article, $this->editor, deduplicationKey: "feed-{$index}");
            app(NotificationEventProjector::class)->project($event->id);
        }

        $firstPage = $this->actingAs($this->author)->getJson('/api/notifications?limit=1&category=revision&article_tracking_code=NOTIFY&from='.now()->toDateString());
        $firstPage->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull($firstPage->json('meta.next_cursor'));

        $notification = UserNotification::where('recipient_user_id', $this->author->id)->firstOrFail();
        $this->actingAs($this->author)->patchJson("/api/notifications/{$notification->id}/visibility", ['state' => 'dismissed'])->assertOk();
        $this->actingAs($this->author)->getJson('/api/notifications?tab=dismissed')->assertOk()->assertJsonPath('data.0.id', $notification->id);
        $this->actingAs($this->author)->patchJson("/api/notifications/{$notification->id}/visibility", ['state' => 'active'])
            ->assertOk()->assertJsonPath('data.visibility', 'active');
    }

    public function test_lost_subject_access_keeps_safe_history_but_removes_link_and_action(): void
    {
        $event = app(NotificationEventRecorder::class)->record('revision.requested', $this->article, $this->editor);
        app(NotificationEventProjector::class)->project($event->id);
        $notification = UserNotification::where('recipient_user_id', $this->author->id)->firstOrFail();
        $this->article->update(['user_id' => $this->other->id]);

        $this->actingAs($this->author)->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.unavailable', true)
            ->assertJsonPath('data.deep_link', null)
            ->assertJsonPath('data.action.available', false);
    }

    public function test_published_article_link_is_server_resolved_by_publication_type(): void
    {
        $this->article->update(['status' => ArticleStatus::PUBLISHED]);
        $event = app(NotificationEventRecorder::class)->record('article.published', $this->article->fresh(), $this->editor);
        app(NotificationEventProjector::class)->project($event->id);
        $notification = UserNotification::where('recipient_user_id', $this->author->id)->firstOrFail();

        $this->actingAs($this->author)->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.deep_link.key', 'article.public')
            ->assertJsonPath('data.deep_link.href', '/magazines/notification-journal/articles/private-review-article');
    }

    public function test_delivery_diagnostics_are_permission_guarded_redacted_and_retry_failed_mail(): void
    {
        $permission = Permission::where('name', 'notifications.delivery.manage')->firstOrFail();
        $this->editor->role->permissions()->attach($permission);
        $log = NotificationLog::create([
            'user_id' => $this->author->id,
            'recipient_email' => 'private.person@example.test',
            'subject' => 'Private subject',
            'payload' => ['greeting' => 'Secret greeting', 'bodyLines' => ['Sensitive body']],
            'status' => 'failed',
            'channel' => 'email',
            'purpose' => 'test.delivery',
            'failed_at' => now(),
            'last_error_code' => 'TransportException',
            'last_error_summary' => 'Connection refused',
        ]);

        $this->actingAs($this->other)->getJson('/api/admin/notification-deliveries')->assertForbidden();
        $response = $this->actingAs($this->editor)->getJson("/api/admin/notification-deliveries/{$log->id}");
        $response->assertOk()->assertJsonMissing(['payload'])->assertJsonMissing(['subject']);
        $this->assertStringNotContainsString('private.person@example.test', $response->getContent());
        $this->actingAs($this->editor)->getJson('/api/admin/notification-deliveries')
            ->assertOk()
            ->assertJsonPath('meta.features.enabled', true)
            ->assertJsonStructure(['meta' => ['permanently_failed_outbox_count', 'outbox_failures', 'features']]);
        $this->actingAs($this->editor)->postJson("/api/admin/notification-deliveries/{$log->id}/retry")
            ->assertOk()->assertJsonPath('data.status', 'queued');
    }
}
