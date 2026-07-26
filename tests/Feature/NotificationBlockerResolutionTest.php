<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Jobs\ProcessNotificationEventJob;
use App\Jobs\SendNotificationJob;
use App\Listeners\SendArticleWorkflowNotifications;
use App\Mail\GenericSystemMail;
use App\Models\Article;
use App\Models\ArticleAuthor;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\NotificationLog;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationEventProjector;
use App\Services\Notifications\NotificationEventRecorder;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class NotificationBlockerResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $editor;

    private Magazine $magazine;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->magazine = Magazine::create([
            'title' => 'Blocker Review Journal',
            'slug' => 'blocker-review-journal',
            'description' => 'Notification blocker tests',
            'publication_type' => Magazine::TYPE_MAGAZINE,
        ]);
        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Atomic Notification Article',
            'slug' => 'atomic-notification-article',
            'tracking_code' => 'SN-BLOCK-001',
            'abstract' => 'Abstract',
            'full_text' => 'Manuscript',
            'status' => ArticleStatus::SCREENING,
        ]);
        $version = ArticleVersion::create([
            'article_id' => $this->article->id,
            'created_by' => $this->author->id,
            'version_number' => 1,
            'status_snapshot' => ArticleStatus::SCREENING,
            'submitted_at' => now(),
            'screening_status' => 'pending',
        ]);
        $this->article->update(['current_version_id' => $version->id]);
    }

    public function test_dead_registry_events_are_removed_and_screening_emits_only_concrete_outcome(): void
    {
        foreach (['article.screening_required', 'article.screened', 'sub_editor.assignment_superseded'] as $removed) {
            $this->assertArrayNotHasKey($removed, config('notification_system.templates'));
        }

        $this->actingAs($this->editor)->postJson("/api/admin/articles/{$this->article->id}/screen", [
            'decision' => 'reject',
            'comments' => 'Out of scope.',
        ])->assertOk();

        $this->assertDatabaseHas('notification_events', ['event_type' => 'article.desk_rejected', 'article_id' => $this->article->id]);
        $this->assertDatabaseCount('notification_events', 1);
    }

    public function test_author_audience_includes_linked_coauthors_and_deduplicates_author_relationships(): void
    {
        $corresponding = User::factory()->create(['role_id' => $this->author->role_id]);
        $ordinary = User::factory()->create(['role_id' => $this->author->role_id]);
        $unauthorized = User::factory()->create(['role_id' => $this->author->role_id]);
        ArticleAuthor::create(['article_id' => $this->article->id, 'user_id' => $this->author->id, 'co_author_name' => $this->author->name, 'co_author_email' => $this->author->email, 'is_owner' => true, 'is_corresponding' => true, 'author_order' => 1]);
        ArticleAuthor::create(['article_id' => $this->article->id, 'user_id' => $corresponding->id, 'co_author_name' => $corresponding->name, 'co_author_email' => $corresponding->email, 'is_corresponding' => true, 'author_order' => 2]);
        ArticleAuthor::create(['article_id' => $this->article->id, 'user_id' => $ordinary->id, 'co_author_name' => $ordinary->name, 'co_author_email' => $ordinary->email, 'author_order' => 3]);
        ArticleAuthor::create(['article_id' => $this->article->id, 'user_id' => null, 'co_author_name' => 'Email Only', 'co_author_email' => 'email-only@example.test', 'author_order' => 4]);

        $event = app(NotificationEventRecorder::class)->record('revision.requested', $this->article->fresh(), $this->editor);
        app(NotificationEventProjector::class)->project($event->id);

        $this->assertSame(1, UserNotification::where('notification_event_id', $event->id)->where('recipient_user_id', $this->author->id)->count());
        $this->assertDatabaseHas('user_notifications', ['notification_event_id' => $event->id, 'recipient_user_id' => $corresponding->id, 'privacy_variant' => 'author', 'action_status' => 'pending']);
        $this->assertDatabaseHas('user_notifications', ['notification_event_id' => $event->id, 'recipient_user_id' => $ordinary->id, 'privacy_variant' => 'co_author', 'action_status' => 'none']);
        $this->assertDatabaseMissing('user_notifications', ['notification_event_id' => $event->id, 'recipient_user_id' => $unauthorized->id]);
        $this->assertSame(3, UserNotification::where('notification_event_id', $event->id)->count());

        $reviewer = User::factory()->create(['role_id' => Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true])->id]);
        (new SendArticleWorkflowNotifications(app(NotificationService::class)))->handle(new ArticleWorkflowEventOccurred(
            $this->article->fresh(), 'reviewer.assigned', $this->editor, ['reviewer' => $reviewer]
        ));
        $coAuthorMail = NotificationLog::where('recipient_email', $ordinary->email)->latest('id')->firstOrFail();
        $this->assertStringContainsString('A reviewer has been invited', implode(' ', $coAuthorMail->payload['bodyLines']));
        $this->assertStringNotContainsString($reviewer->name, $coAuthorMail->toJson());
        $this->assertNull($coAuthorMail->payload['action']);
    }

    public function test_mandatory_security_events_create_one_safe_in_app_row_and_email_log_each(): void
    {
        foreach (['account.email_changed', 'account.mfa_method_changed', 'account.role_or_scope_changed'] as $type) {
            $event = app(NotificationEventRecorder::class)->record(
                $type, null, $this->author,
                ['recipient_user_id' => $this->author->id, 'recipient_privacy_variant' => 'account'],
                'user', $this->author->id, deduplicationKey: 'security-'.$type
            );
            app(NotificationEventProjector::class)->project($event->id);

            $this->assertSame(1, UserNotification::where('notification_event_id', $event->id)->count());
            $this->assertSame(1, NotificationLog::where('notification_event_id', $event->id)->count());
            $serialized = UserNotification::where('notification_event_id', $event->id)->firstOrFail()->toJson()
                .NotificationLog::where('notification_event_id', $event->id)->firstOrFail()->toJson();
            foreach (['123456', 'secret-token', 'recovery-code-value', 'private-key-value'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtolower($serialized));
            }
        }
    }

    public function test_security_state_changes_remain_committed_when_queued_email_delivery_fails(): void
    {
        $newRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $changes = [
            'account.email_changed' => fn () => $this->author->update(['email' => 'changed@example.test']),
            'account.mfa_method_changed' => fn () => $this->author->update(['two_factor_enabled' => true]),
            'account.role_or_scope_changed' => fn () => $this->author->update(['role_id' => $newRole->id]),
        ];
        $logs = collect();

        foreach ($changes as $type => $change) {
            $event = DB::transaction(function () use ($type, $change) {
                $change();

                return app(NotificationEventRecorder::class)->record(
                    $type, null, $this->author,
                    ['recipient_user_id' => $this->author->id, 'recipient_privacy_variant' => 'account'],
                    'user', $this->author->id, deduplicationKey: 'failure-'.$type
                );
            });
            app(NotificationEventProjector::class)->project($event->id);
            $logs->push(NotificationLog::where('notification_event_id', $event->id)->firstOrFail());
        }

        Mail::shouldReceive('to')->times(3)->andReturnSelf();
        Mail::shouldReceive('send')->times(3)->andThrow(new RuntimeException('secret-token private-file.pdf hidden@example.test'));
        foreach ($logs as $log) {
            try {
                (new SendNotificationJob($log->id))->handle();
            } catch (RuntimeException) {
            }
            $this->assertSame('failed', $log->fresh()->status);
            $this->assertSame('Notification delivery failed (RuntimeException).', $log->fresh()->last_error_summary);
        }

        $this->author->refresh();
        $this->assertSame('changed@example.test', $this->author->email);
        $this->assertTrue((bool) $this->author->two_factor_enabled);
        $this->assertSame($newRole->id, $this->author->role_id);
    }

    public function test_historical_rendering_is_immutable_after_active_template_changes(): void
    {
        $event = app(NotificationEventRecorder::class)->record('article.under_review', $this->article, $this->editor);
        app(NotificationEventProjector::class)->project($event->id);
        $notification = UserNotification::where('notification_event_id', $event->id)->where('recipient_user_id', $this->author->id)->firstOrFail();
        $originalTitle = $notification->rendered_title;
        $originalBody = $notification->rendered_body;

        $templates = config('notification_system.templates');
        $templates['article.under_review']['title'] = 'CHANGED ACTIVE TITLE';
        $templates['article.under_review']['body'] = 'CHANGED ACTIVE BODY';
        config()->set('notification_system.templates', $templates);

        $this->actingAs($this->author)->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.title', $originalTitle)
            ->assertJsonPath('data.body', $originalBody);

        $unsupported = app(NotificationEventRecorder::class)->record(
            'article.under_review', $this->article, $this->editor, deduplicationKey: 'unsupported-template-version'
        );
        $unsupported->update(['schema_version' => 99]);
        try {
            app(NotificationEventProjector::class)->project($unsupported->id);
            $this->fail('Unsupported historical versions must not fall back to the active template.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseMissing('user_notifications', ['notification_event_id' => $unsupported->id]);
            $this->assertSame('InvalidArgumentException', $unsupported->fresh()->failure_code);
        }
    }

    public function test_operational_switches_do_not_mutate_workflow_or_delete_pending_work(): void
    {
        config()->set('notification_system.features.enabled', false);
        $this->assertNull(app(NotificationEventRecorder::class)->record('article.under_review', $this->article, $this->editor));
        $this->assertDatabaseCount('notification_events', 0);

        config()->set('notification_system.features.enabled', true);
        config()->set('notification_system.features.in_app', false);
        $event = app(NotificationEventRecorder::class)->record('article.version_created', $this->article, $this->editor);
        app(NotificationEventProjector::class)->project($event->id);
        $this->assertDatabaseHas('user_notifications', ['notification_event_id' => $event->id, 'in_app_visible' => false, 'email_mode' => 'immediate']);

        config()->set('notification_system.features.in_app', true);
        config()->set('notification_system.features.email_projection', false);
        $emailDisabled = app(NotificationEventRecorder::class)->record('article.version_created', $this->article, $this->editor, deduplicationKey: 'email-switch');
        app(NotificationEventProjector::class)->project($emailDisabled->id);
        $this->assertDatabaseHas('user_notifications', ['notification_event_id' => $emailDisabled->id, 'in_app_visible' => true, 'email_mode' => 'off']);
        $mandatory = app(NotificationEventRecorder::class)->record(
            'account.email_changed', null, $this->author,
            ['recipient_user_id' => $this->author->id, 'recipient_privacy_variant' => 'account'],
            'user', $this->author->id, deduplicationKey: 'mandatory-email-switch'
        );
        app(NotificationEventProjector::class)->project($mandatory->id);
        $this->assertDatabaseHas('notification_logs', ['notification_event_id' => $mandatory->id, 'purpose' => 'account.email_changed']);

        config()->set('notification_system.features.digests', false);
        UserNotification::where('notification_event_id', $event->id)->update(['email_mode' => 'digest', 'digest_frequency' => 'daily']);
        Artisan::call('notifications:send-digests', ['--force' => true]);
        $this->assertNull(UserNotification::where('notification_event_id', $event->id)->value('digest_sent_at'));

        config()->set('notification_system.features.reminders', false);
        Artisan::call('workflow:send-deadline-reminders');
        $this->assertDatabaseCount('workflow_deadline_reminder_logs', 0);
    }

    public function test_outbox_recovery_handles_lost_dispatch_stale_claim_locking_and_attempt_limits(): void
    {
        $event = app(NotificationEventRecorder::class)->record('article.under_review', $this->article, $this->editor);
        Queue::fake();
        Artisan::call('notifications:recover-outbox');
        Queue::assertPushed(ProcessNotificationEventJob::class, fn ($job) => $job->notificationEventId === $event->id);

        $event->update(['processing_at' => now()->subMinutes(11)]);
        Queue::fake();
        Artisan::call('notifications:recover-outbox');
        Queue::assertPushed(ProcessNotificationEventJob::class, fn ($job) => $job->notificationEventId === $event->id);

        $lock = Cache::lock('notifications:recover-outbox', 55);
        $this->assertTrue($lock->get());
        Queue::fake();
        Artisan::call('notifications:recover-outbox');
        Queue::assertNothingPushed();
        $lock->release();

        $event->update(['processing_at' => null, 'attempt_count' => config('notification_system.outbox.max_attempts')]);
        Artisan::call('notifications:recover-outbox');
        $this->assertNotNull($event->fresh()->permanently_failed_at);
        $this->assertSame('max_attempts_exceeded', $event->fresh()->failure_code);
    }

    public function test_multi_role_user_keeps_materially_distinct_variants(): void
    {
        ArticleAuthor::create(['article_id' => $this->article->id, 'user_id' => $this->editor->id, 'co_author_name' => $this->editor->name, 'co_author_email' => $this->editor->email, 'is_corresponding' => true, 'author_order' => 2]);
        $event = app(NotificationEventRecorder::class)->record('article.accepted', $this->article->fresh(), $this->editor);
        app(NotificationEventProjector::class)->project($event->id);

        $variants = UserNotification::where('notification_event_id', $event->id)->where('recipient_user_id', $this->editor->id)
            ->orderBy('privacy_variant')->pluck('privacy_variant')->all();
        $this->assertSame([], $variants, 'The triggering actor must not receive their own workflow notification.');

        $invalid = app(NotificationEventRecorder::class)->record(
            'account.email_changed', null, $this->author,
            ['recipient_user_id' => $this->author->id, 'recipient_privacy_variant' => 'reviewer'],
            'user', $this->author->id, deduplicationKey: 'invalid-account-variant'
        );
        $this->expectException(InvalidArgumentException::class);
        app(NotificationEventProjector::class)->project($invalid->id);
    }

    public function test_author_facing_notification_matrix_excludes_reviewer_identity_and_storage_tokens(): void
    {
        $forbidden = [
            'Dr Hidden Reviewer', 'hidden-reviewer@example.test', 'Reviewer Institute',
            'avatar-private.png', 'invite-secret-value', 'review-private.pdf', 'private/s3/key',
        ];
        $types = [
            'article.submitted', 'article.desk_rejected', 'article.under_review',
            'transfer.requested', 'transfer.accepted', 'transfer.rejected',
            'sub_editor.assigned', 'reviewer.assigned', 'revision.requested',
            'article.resubmitted', 'article.version_created', 'article.accepted', 'article.rejected',
            'production.assigned', 'production.completed', 'author.final_review_denied',
            'author.final_review_requested', 'author.final_review_reminder',
            'author.final_review_approved', 'author.final_review_auto_approved',
            'article.ready_for_publication', 'article.issue_assigned', 'issue.published',
            'article.published', 'post_publication.recorded',
        ];

        foreach ($types as $index => $type) {
            $event = app(NotificationEventRecorder::class)->record($type, $this->article, $this->editor, [
                'reviewer_id' => 918273,
                'reviewer_name' => $forbidden[0],
                'reviewer_email' => $forbidden[1],
                'profile' => ['institution' => $forbidden[2], 'avatar' => $forbidden[3]],
                'invite_token' => $forbidden[4],
                'filename' => $forbidden[5],
                's3_key' => $forbidden[6],
            ], deduplicationKey: "author-privacy-matrix-{$index}");
            app(NotificationEventProjector::class)->project($event->id);

            $rows = UserNotification::where('notification_event_id', $event->id)
                ->where('recipient_user_id', $this->author->id)->get();
            $this->assertNotEmpty($rows, "{$type} must reach the author audience.");
            $this->assertForbiddenTokens($rows->toJson().$event->fresh()->toJson(), $forbidden);
        }
    }

    public function test_reviewer_delivery_surfaces_exclude_author_identity_and_secret_fields(): void
    {
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $reviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $assignment = ReviewerAssignment::create([
            'article_id' => $this->article->id,
            'reviewer_id' => $reviewer->id,
            'invitee_name' => $reviewer->name,
            'invitee_email' => $reviewer->email,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'invite_token_hash' => hash('sha256', 'invite-secret-value'),
            'invite_expires_at' => now()->subMinute(),
        ]);
        $forbidden = [
            $this->author->name, $this->author->email, 'Author Institute', 'author-avatar.png',
            'invite-secret-value', 'author-private.pdf', 'private/author/key',
        ];

        $event = app(NotificationEventRecorder::class)->record(
            'review.invitation_expired', $this->article, $this->editor,
            [
                'assignment_id' => $assignment->id,
                'recipient_user_id' => $reviewer->id,
                'recipient_privacy_variant' => 'reviewer',
                'author_name' => $forbidden[0],
                'author_email' => $forbidden[1],
                'author' => ['institution' => $forbidden[2], 'avatar' => $forbidden[3]],
                'invite_token' => $forbidden[4],
                'filename' => $forbidden[5],
                's3_key' => $forbidden[6],
            ],
            'reviewer_assignment', $assignment->id,
            deduplicationKey: 'reviewer-privacy-surfaces'
        );
        app(NotificationEventProjector::class)->project($event->id);

        $notification = UserNotification::where('notification_event_id', $event->id)->firstOrFail();
        $log = NotificationLog::where('notification_event_id', $event->id)->firstOrFail();
        $mail = new GenericSystemMail(
            $log->recipient_email,
            $log->subject,
            $log->payload['greeting'],
            $log->payload['bodyLines'],
            $log->payload['action']
        );
        $plain = view('emails.generic-text', $log->payload)->render();
        $queuedJob = Queue::pushed(SendNotificationJob::class)->first();
        $surfaces = implode('\n', [
            $notification->rendered_title,
            $notification->rendered_body,
            json_encode($notification->render_data),
            json_encode($notification->deep_link_params),
            (string) $notification->group_key,
            $log->subject,
            $mail->render(),
            $plain,
            $log->toJson(),
            json_encode($queuedJob),
            (string) $event->fresh()->last_error,
        ]);
        $this->assertForbiddenTokens($surfaces, $forbidden);
        $this->assertSame('reviewer', $notification->privacy_variant);
    }

    public function test_workflow_queue_payloads_contain_only_opaque_delivery_and_outbox_ids(): void
    {
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $reviewer = User::factory()->create(['id' => 918273, 'name' => 'Dr Queue Secret', 'email' => 'queue-secret@example.test', 'role_id' => $reviewerRole->id]);
        ReviewerAssignment::create([
            'article_id' => $this->article->id, 'reviewer_id' => $reviewer->id,
            'invitee_name' => $reviewer->name, 'invitee_email' => $reviewer->email,
            'assigned_by' => $this->editor->id, 'status' => 'pending',
        ]);
        Queue::fake();

        DB::transaction(function () use ($reviewer) {
            event(new ArticleWorkflowEventOccurred(
                $this->article, 'reviewer.assigned', $this->editor,
                ['assignment_id' => 1, 'reviewer' => $reviewer]
            ));
        });

        $serializedJobs = Queue::pushed(ProcessNotificationEventJob::class)->merge(Queue::pushed(SendNotificationJob::class))
            ->map(fn ($job) => serialize($job))->implode('\n');
        $this->assertNotSame('', $serializedJobs);
        $this->assertForbiddenTokens($serializedJobs, [
            (string) $reviewer->id, $reviewer->name, $reviewer->email, 'invite-secret-value',
        ]);
    }

    private function assertForbiddenTokens(string $surface, array $forbidden): void
    {
        foreach ($forbidden as $token) {
            $this->assertStringNotContainsString(strtolower($token), strtolower($surface), "Sensitive token [{$token}] leaked.");
        }
        foreach (['reviewer_id', 'reviewer_name', 'reviewer_email', 'invite_token', 's3_key', 'filename'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', strtolower($surface), "Sensitive key [{$key}] leaked.");
        }
    }
}
