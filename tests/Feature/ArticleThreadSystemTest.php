<?php

namespace Tests\Feature;

use App\Constants\ArticleThreadType;
use App\Models\Article;
use App\Models\ArticleThread;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\ArticleThreadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleThreadSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private User $author;

    private User $reviewer;

    private User $publisher;

    private Magazine $magazine;

    private Article $article;

    private ArticleVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $roles = collect(['super_admin', 'editor', 'author', 'reviewer', 'publisher'])->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'display_name' => str($name)->headline(), 'is_system' => true])]);
        $this->admin = User::factory()->create(['role_id' => $roles['super_admin']->id]);
        $this->editor = User::factory()->create(['role_id' => $roles['editor']->id]);
        $this->author = User::factory()->create(['role_id' => $roles['author']->id]);
        $this->reviewer = User::factory()->create(['role_id' => $roles['reviewer']->id]);
        $this->publisher = User::factory()->create(['role_id' => $roles['publisher']->id]);
        $this->magazine = Magazine::create(['title' => 'Thread Journal', 'slug' => 'thread-journal', 'description' => 'Test', 'publication_type' => 'journal', 'is_active' => true]);
        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $this->publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);
        $this->article = Article::create(['magazine_id' => $this->magazine->id, 'user_id' => $this->author->id, 'title' => 'Threaded scholarship', 'slug' => 'threaded-scholarship', 'abstract' => 'Abstract', 'full_text' => '', 'status' => 'submitted']);
        $this->version = ArticleVersion::create(['article_id' => $this->article->id, 'created_by' => $this->author->id, 'version_number' => 1, 'label' => 'Initial Submission', 'status_snapshot' => 'submitted']);
        $this->article->update(['current_version_id' => $this->version->id]);
        app(ArticleThreadService::class)->ensureSubmissionThreads($this->article->fresh(), $this->author);
    }

    public function test_default_threads_are_unique_and_role_scoped(): void
    {
        app(ArticleThreadService::class)->ensureSubmissionThreads($this->article->fresh(), $this->author);
        $this->assertDatabaseCount('article_threads', 3);
        Sanctum::actingAs($this->author);
        $response = $this->getJson("/api/articles/{$this->article->id}/threads")->assertOk();
        $this->assertEqualsCanonicalizing(['author_editor', 'system_activity'], collect($response->json('data'))->pluck('type')->all());
        $this->assertNotContains('editorial_internal', collect($response->json('data'))->pluck('type')->all());
    }

    public function test_messages_replies_mentions_unread_and_locking_are_authoritative(): void
    {
        $thread = ArticleThread::where('article_id', $this->article->id)->where('thread_type', ArticleThreadType::AUTHOR_EDITOR)->firstOrFail();
        Sanctum::actingAs($this->editor);
        $message = $this->postJson("/api/articles/{$this->article->id}/threads/{$thread->id}/messages", [
            'body' => '<script>alert(1)</script>Hello author', 'mentions' => [$this->author->id], 'client_request_id' => 'editor-message-1',
        ])->assertCreated()->assertJsonMissing(['<script>'])->json('data');
        $this->assertDatabaseHas('notification_events', ['event_type' => 'article_thread.mentioned', 'article_id' => $this->article->id, 'subject_type' => 'article_thread', 'subject_id' => $thread->id]);
        $this->assertDatabaseHas('notification_events', ['event_type' => 'article_thread.message_posted', 'article_id' => $this->article->id, 'subject_type' => 'article_thread', 'subject_id' => $thread->id]);
        $this->postJson("/api/articles/{$this->article->id}/threads/{$thread->id}/messages", [
            'body' => 'Duplicate retry', 'client_request_id' => 'editor-message-1',
        ])->assertCreated()->assertJsonPath('data.id', $message['id']);
        Sanctum::actingAs($this->author);
        $this->getJson("/api/articles/{$this->article->id}/threads/unread-count")->assertOk()->assertJsonPath('data.unread_count', 1);
        $reply = $this->postJson("/api/articles/{$this->article->id}/threads/{$thread->id}/messages", [
            'body' => 'Thank you', 'parent_message_id' => $message['id'], 'client_request_id' => 'author-reply-1',
        ])->assertCreated()->assertJsonPath('data.parent.id', $message['id']);
        $this->postJson("/api/articles/{$this->article->id}/threads/{$thread->id}/read", ['message_id' => $reply->json('data.id')])->assertOk();
        $this->getJson("/api/articles/{$this->article->id}/threads/unread-count")->assertJsonPath('data.unread_count', 0);
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/articles/{$this->article->id}/threads/{$thread->id}/lock")->assertOk()->assertJsonPath('data.status', 'locked');
        Sanctum::actingAs($this->author);
        $this->postJson("/api/articles/{$this->article->id}/threads/{$thread->id}/messages", ['body' => 'Blocked', 'client_request_id' => 'blocked'])->assertConflict();
    }

    public function test_reviewer_thread_is_isolated_and_revocation_is_immediate(): void
    {
        $assignment = ReviewerAssignment::create(['article_id' => $this->article->id, 'article_version_id' => $this->version->id, 'round_number' => 1,
            'reviewer_id' => $this->reviewer->id, 'invitee_email' => $this->reviewer->email, 'assigned_by' => $this->editor->id, 'status' => 'accepted', 'accepted_at' => now()]);
        $thread = app(ArticleThreadService::class)->ensureReviewerThread($assignment, $this->editor);
        Sanctum::actingAs($this->author);
        $this->getJson("/api/articles/{$this->article->id}/threads/{$thread->id}")->assertNotFound();
        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/articles/{$this->article->id}/threads/{$thread->id}")->assertOk()->assertJsonMissing([$this->author->email]);
        $assignment->update(['revoked_at' => now(), 'status' => 'completed']);
        $this->getJson("/api/articles/{$this->article->id}/threads/{$thread->id}")->assertNotFound();
    }

    public function test_direct_publication_thread_allows_only_scoped_publisher_and_super_admin(): void
    {
        $direct = Article::create(['magazine_id' => $this->magazine->id, 'title' => 'Direct record', 'slug' => 'direct-record', 'abstract' => 'Abstract', 'full_text' => '',
            'status' => 'direct_publication_draft', 'lifecycle_status' => 'direct_publication_draft', 'submission_mode' => 'direct_publication', 'directly_created_by' => $this->admin->id]);
        $version = ArticleVersion::create(['article_id' => $direct->id, 'created_by' => $this->admin->id, 'version_number' => 1, 'label' => 'Direct', 'source' => 'direct_publication', 'status_snapshot' => 'direct_publication_draft']);
        $direct->update(['current_version_id' => $version->id]);
        $thread = app(ArticleThreadService::class)->ensureDirectPublicationThread($direct->fresh(), $this->admin);
        Sanctum::actingAs($this->publisher);
        $this->getJson("/api/articles/{$direct->id}/threads/{$thread->id}")->assertOk()->assertJsonPath('data.privacy_classification', 'direct_publication_confidential');
        Sanctum::actingAs($this->editor);
        $this->getJson("/api/articles/{$direct->id}/threads/{$thread->id}")->assertNotFound();
        Sanctum::actingAs($this->author);
        $this->getJson("/api/articles/{$direct->id}/threads")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_removed_participant_loses_access_and_cannot_be_mentioned(): void
    {
        $thread = ArticleThread::where('article_id', $this->article->id)->where('thread_type', ArticleThreadType::AUTHOR_EDITOR)->firstOrFail();
        $participant = $thread->participants()->where('user_id', $this->author->id)->firstOrFail();
        Sanctum::actingAs($this->editor);
        $this->deleteJson("/api/articles/{$this->article->id}/threads/{$thread->id}/participants/{$participant->id}")->assertOk();
        $this->postJson("/api/articles/{$this->article->id}/threads/{$thread->id}/messages", [
            'body' => 'Cannot mention removed author', 'mentions' => [$this->author->id], 'client_request_id' => 'bad-mention',
        ])->assertUnprocessable();
        Sanctum::actingAs($this->author);
        $this->getJson("/api/articles/{$this->article->id}/threads/{$thread->id}")->assertNotFound();
    }
}
