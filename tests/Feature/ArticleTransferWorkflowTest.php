<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleTransferRequest;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private User $author;
    private User $outsider;
    private Magazine $currentMagazine;
    private Magazine $targetMagazine;
    private Magazine $inactiveMagazine;
    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        foreach (['articles.view-own', 'articles.edit-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['module' => 'articles', 'description' => $permission]
            );
        }

        $editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.edit-own'])->pluck('id'));

        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
        $this->outsider = User::factory()->create(['role_id' => $authorRole->id]);

        $this->currentMagazine = Magazine::create([
            'title' => 'Current Magazine',
            'slug' => 'current-magazine',
            'description' => 'Current magazine',
            'is_active' => true,
        ]);
        $this->targetMagazine = Magazine::create([
            'title' => 'Target Magazine',
            'slug' => 'target-magazine',
            'description' => 'Target magazine',
            'is_active' => true,
        ]);
        $this->inactiveMagazine = Magazine::create([
            'title' => 'Inactive Magazine',
            'slug' => 'inactive-magazine',
            'description' => 'Inactive magazine',
            'is_active' => false,
        ]);

        $this->editor->magazines()->attach($this->currentMagazine->id, ['role' => 'editor']);

        $this->article = Article::create([
            'magazine_id' => $this->currentMagazine->id,
            'user_id' => $this->author->id,
            'title' => 'Transfer Article',
            'slug' => 'transfer-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::SUBMITTED,
        ]);
    }

    public function test_target_magazine_list_excludes_current_inactive_and_includes_unassigned_active_magazines(): void
    {
        Sanctum::actingAs($this->editor);

        $this->getJson("/api/articles/{$this->article->id}/transfer-target-magazines")
            ->assertOk()
            ->assertJsonFragment(['id' => $this->targetMagazine->id, 'name' => 'Target Magazine'])
            ->assertJsonMissing(['id' => $this->currentMagazine->id, 'name' => 'Current Magazine'])
            ->assertJsonMissing(['id' => $this->inactiveMagazine->id, 'name' => 'Inactive Magazine']);
    }

    public function test_editor_can_create_transfer_request_during_screening(): void
    {
        Sanctum::actingAs($this->editor);

        $this->postJson("/api/articles/{$this->article->id}/transfer-requests", [
            'to_magazine_id' => $this->targetMagazine->id,
            'editor_comments' => 'Better fit for the target magazine.',
        ])->assertCreated()
            ->assertJsonPath('article.status', ArticleStatus::IN_TRANSIT)
            ->assertJsonPath('transfer_request.status', ArticleTransferRequest::STATUS_PENDING);

        $this->assertDatabaseHas('article_transfer_requests', [
            'article_id' => $this->article->id,
            'from_magazine_id' => $this->currentMagazine->id,
            'to_magazine_id' => $this->targetMagazine->id,
            'status' => ArticleTransferRequest::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('articles', [
            'id' => $this->article->id,
            'status' => ArticleStatus::IN_TRANSIT,
        ]);
        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'transfer.requested',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => $this->author->email,
            'subject' => 'Magazine Transfer Request: Transfer Article',
        ]);
    }

    public function test_editor_cannot_create_transfer_outside_screening_same_magazine_or_duplicate_pending(): void
    {
        Sanctum::actingAs($this->editor);

        $this->article->update(['status' => ArticleStatus::UNDER_REVIEW]);
        $this->postJson("/api/articles/{$this->article->id}/transfer-requests", [
            'to_magazine_id' => $this->targetMagazine->id,
            'editor_comments' => 'Move it.',
        ])->assertStatus(422);

        $this->article->update(['status' => ArticleStatus::SUBMITTED]);
        $this->postJson("/api/articles/{$this->article->id}/transfer-requests", [
            'to_magazine_id' => $this->currentMagazine->id,
            'editor_comments' => 'Move it.',
        ])->assertStatus(422);

        $this->postJson("/api/articles/{$this->article->id}/transfer-requests", [
            'to_magazine_id' => $this->targetMagazine->id,
            'editor_comments' => 'Move it.',
        ])->assertCreated();

        Article::whereKey($this->article->id)->update(['status' => ArticleStatus::SUBMITTED]);
        $this->postJson("/api/articles/{$this->article->id}/transfer-requests", [
            'to_magazine_id' => $this->targetMagazine->id,
            'editor_comments' => 'Duplicate move.',
        ])->assertStatus(422);

        ArticleTransferRequest::where('article_id', $this->article->id)->update(['status' => ArticleTransferRequest::STATUS_REJECTED]);
        Article::whereKey($this->article->id)->update(['status' => ArticleStatus::SUBMITTED]);
        $this->postJson("/api/articles/{$this->article->id}/transfer-requests", [
            'to_magazine_id' => $this->inactiveMagazine->id,
            'editor_comments' => 'Move it again.',
        ])->assertStatus(422);
    }

    public function test_author_can_accept_pending_transfer(): void
    {
        $transferRequest = $this->createPendingTransfer();

        Sanctum::actingAs($this->author);

        $this->postJson("/api/articles/{$this->article->id}/transfer-requests/{$transferRequest->id}/accept")
            ->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::SUBMITTED)
            ->assertJsonPath('article.magazine_id', $this->targetMagazine->id);

        $this->assertDatabaseHas('article_transfer_requests', [
            'id' => $transferRequest->id,
            'status' => ArticleTransferRequest::STATUS_ACCEPTED,
            'responded_by_user_id' => $this->author->id,
        ]);
        $this->assertDatabaseHas('articles', [
            'id' => $this->article->id,
            'magazine_id' => $this->targetMagazine->id,
            'status' => ArticleStatus::SUBMITTED,
        ]);
        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'transfer.magazine_changed',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => $this->editor->email,
            'subject' => 'Magazine Transfer Accepted: Transfer Article',
        ]);
    }

    public function test_author_can_reject_pending_transfer_with_reason(): void
    {
        $transferRequest = $this->createPendingTransfer();

        Sanctum::actingAs($this->author);

        $this->postJson("/api/articles/{$this->article->id}/transfer-requests/{$transferRequest->id}/reject", [])
            ->assertStatus(422);

        $this->postJson("/api/articles/{$this->article->id}/transfer-requests/{$transferRequest->id}/reject", [
            'author_rejection_reason' => 'I prefer the original magazine.',
        ])->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::SUBMITTED)
            ->assertJsonPath('article.magazine_id', $this->currentMagazine->id);

        $this->assertDatabaseHas('article_transfer_requests', [
            'id' => $transferRequest->id,
            'status' => ArticleTransferRequest::STATUS_REJECTED,
            'author_rejection_reason' => 'I prefer the original magazine.',
        ]);
        $this->assertDatabaseHas('articles', [
            'id' => $this->article->id,
            'magazine_id' => $this->currentMagazine->id,
            'status' => ArticleStatus::SUBMITTED,
        ]);
        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'transfer.rejected',
        ]);
    }

    public function test_unauthorized_user_cannot_respond_and_screening_actions_are_blocked_in_transit(): void
    {
        $transferRequest = $this->createPendingTransfer();

        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/articles/{$this->article->id}/transfer-requests/{$transferRequest->id}/accept")
            ->assertForbidden();

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$this->article->id}/screen", [
            'decision' => 'send_to_review',
            'comments' => 'Continue.',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This article is in transit awaiting author transfer approval. Resolve the transfer request before continuing editorial workflow actions.');
    }

    private function createPendingTransfer(): ArticleTransferRequest
    {
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/articles/{$this->article->id}/transfer-requests", [
            'to_magazine_id' => $this->targetMagazine->id,
            'editor_comments' => 'Better fit for the target magazine.',
        ])->assertCreated();

        return ArticleTransferRequest::where('article_id', $this->article->id)->firstOrFail();
    }
}
