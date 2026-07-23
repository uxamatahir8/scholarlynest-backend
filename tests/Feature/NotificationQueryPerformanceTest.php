<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\NotificationEvent;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationQueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public static function feedRoles(): array
    {
        return [
            'author' => ['author'],
            'editor' => ['editor'],
            'reviewer' => ['reviewer'],
            'publisher' => ['publisher'],
            'support owner' => ['support'],
        ];
    }

    #[DataProvider('feedRoles')]
    public function test_feed_authorization_queries_are_bounded_for_25_and_100_mixed_items(string $accessRole): void
    {
        [$user] = $this->seedFeed($accessRole, 100, 10);

        $user->unsetRelation('role');
        $twentyFive = $this->queryCount(fn () => $this->actingAs($user)->getJson('/api/notifications?limit=25')->assertOk()->assertJsonCount(25, 'data'));
        $user->unsetRelation('role');
        $hundred = $this->queryCount(fn () => $this->actingAs($user)->getJson('/api/notifications?limit=100')->assertOk()->assertJsonCount(100, 'data'));

        $this->assertLessThanOrEqual(18, $twentyFive, "{$accessRole} 25-item feed issued {$twentyFive} queries.");
        $this->assertLessThanOrEqual(18, $hundred, "{$accessRole} 100-item feed issued {$hundred} queries.");
        $this->assertLessThanOrEqual($twentyFive + 2, $hundred, "{$accessRole} feed query count grows with row count ({$twentyFive} -> {$hundred}).");
    }

    public function test_dropdown_request_path_has_bounded_queries(): void
    {
        [$user] = $this->seedFeed('author', 100, 10, true);

        $queries = $this->queryCount(function () use ($user) {
            $this->actingAs($user)->getJson('/api/notifications?limit=8')->assertOk();
            $this->actingAs($user)->getJson('/api/notifications?tab=action_required&limit=5')->assertOk();
            $this->actingAs($user)->getJson('/api/notifications/counts')->assertOk();
        });

        $this->assertLessThanOrEqual(35, $queries, "Dropdown path issued {$queries} queries.");
    }

    private function seedFeed(string $accessRole, int $items, int $subjects, bool $actions = false): array
    {
        $roleName = $accessRole === 'support' ? 'author' : $accessRole;
        $role = Role::create(['name' => $roleName, 'display_name' => ucfirst($roleName), 'is_system' => true]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $editor = $accessRole === 'reviewer'
            ? User::factory()->create(['role_id' => Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true])->id])
            : $user;
        $magazine = Magazine::create([
            'title' => 'Query Journal', 'slug' => 'query-journal-'.$accessRole,
            'description' => 'Query tests', 'publication_type' => Magazine::TYPE_MAGAZINE,
        ]);
        if (in_array($accessRole, ['editor', 'publisher'], true)) {
            $user->magazines()->attach($magazine->id, ['role' => $accessRole]);
        }

        $models = collect();
        for ($i = 0; $i < $subjects; $i++) {
            if ($accessRole === 'support') {
                $models->push(SupportTicket::create([
                    'ticket_number' => '', 'user_id' => $user->id, 'issue_type' => 'account_issue',
                    'title' => "Ticket {$i}", 'details' => 'Details', 'status' => 'submitted',
                ]));

                continue;
            }
            $article = Article::create([
                'magazine_id' => $magazine->id,
                'user_id' => $accessRole === 'author' ? $user->id : $editor->id,
                'title' => "Query Article {$i}", 'slug' => "query-article-{$accessRole}-{$i}",
                'tracking_code' => "SN-QUERY-{$i}", 'abstract' => 'Abstract', 'full_text' => 'Text',
                'status' => ArticleStatus::UNDER_REVIEW,
            ]);
            if ($accessRole === 'reviewer') {
                ReviewerAssignment::create([
                    'article_id' => $article->id, 'reviewer_id' => $user->id,
                    'invitee_email' => $user->email, 'assigned_by' => $editor->id, 'status' => 'accepted',
                ]);
            }
            $models->push($article);
        }

        for ($i = 0; $i < $items; $i++) {
            $subject = $models[$i % $subjects];
            $isSupport = $accessRole === 'support';
            $event = NotificationEvent::create([
                'event_uuid' => (string) Str::uuid(),
                'event_type' => $isSupport ? 'support.ticket_replied' : 'article.under_review',
                'schema_version' => 1,
                'article_id' => $isSupport ? null : $subject->id,
                'magazine_id' => $isSupport ? null : $magazine->id,
                'subject_type' => $isSupport ? 'support_ticket' : 'article',
                'subject_id' => $subject->id,
                'payload' => [], 'occurred_at' => now(), 'available_at' => now(), 'processed_at' => now(),
            ]);
            UserNotification::create([
                'notification_event_id' => $event->id, 'recipient_user_id' => $user->id,
                'type' => $event->event_type, 'category' => $isSupport ? 'support' : 'editorial',
                'priority' => 'normal', 'severity' => 'info',
                'privacy_variant' => $isSupport ? 'support_owner' : $accessRole,
                'template_version' => 1,
                'title_key' => 'notification.test.title.v1', 'body_key' => 'notification.test.body.v1',
                'rendered_title' => "Notification {$i}", 'rendered_body' => 'Safe body', 'render_data' => [],
                'article_id' => $isSupport ? null : $subject->id,
                'magazine_id' => $isSupport ? null : $magazine->id,
                'subject_type' => $isSupport ? 'support_ticket' : 'article', 'subject_id' => $subject->id,
                'deep_link_key' => $isSupport ? 'support.ticket' : 'article.workflow',
                'deep_link_params' => $isSupport ? ['ticket_id' => $subject->id] : ['article_id' => $subject->id],
                'group_key' => ($isSupport ? 'ticket:' : 'article:').$subject->id,
                'deduplication_key' => hash('sha256', "feed|{$accessRole}|{$i}"),
                'in_app_visible' => true, 'email_mode' => 'off',
                'action_status' => $actions && $i % 3 === 0 ? 'pending' : 'none',
                'action_key' => $actions && $i % 3 === 0 ? 'open_existing_workflow' : null,
            ]);
        }

        return [$user, $models];
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
