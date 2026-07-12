<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAuthor;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $editor;
    private User $subEditor;
    private User $reviewer;
    private User $author;
    private Magazine $magazine;
    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $subEditorRole = Role::create(['name' => 'sub_editor', 'display_name' => 'Sub Editor', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        foreach (['articles.view-own', 'articles.edit-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['module' => 'articles', 'description' => $permission]
            );
        }

        $editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        $subEditorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        $reviewerRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.edit-own'])->pluck('id'));
        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.edit-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->subEditor = User::factory()->create(['role_id' => $subEditorRole->id]);
        $this->reviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'Workflow Magazine',
            'slug' => 'workflow-magazine',
            'description' => 'Workflow test magazine',
        ]);

        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $this->subEditor->magazines()->attach($this->magazine->id, ['role' => 'sub_editor']);
        $this->reviewer->magazines()->attach($this->magazine->id, ['role' => 'reviewer']);

        // Link the sub editor to the editor
        $this->editor->assignedSubEditors()->attach($this->subEditor->id);

        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Workflow Article',
            'slug' => 'workflow-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::SUBMITTED,
        ]);
    }

    public function test_editor_can_assign_sub_editor_and_reviewer(): void
    {
        Sanctum::actingAs($this->editor);

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-sub-editor", [
            'sub_editor_id' => $this->subEditor->id,
        ])->assertStatus(201)
            ->assertJsonPath('article.status', ArticleStatus::ASSIGNED_TO_SUB_EDITOR);

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertStatus(201)
            ->assertJsonPath('article.status', ArticleStatus::REVIEWER_ASSIGNED);

        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'reviewer.assigned',
        ]);
    }

    public function test_article_registry_supports_column_filters_and_scoped_author_options(): void
    {
        $issue = MagazineIssue::create([
            'magazine_id' => $this->magazine->id,
            'volume_number' => 12,
            'issue_number' => 3,
            'special_title' => 'Clinical Engineering',
        ]);
        $this->article->update(['magazine_issue_id' => $issue->id, 'title' => 'Advanced Biomedical Methods']);

        Sanctum::actingAs($this->editor);
        $this->getJson('/api/admin/articles?tracking_code=' . urlencode($this->article->tracking_code))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?tracking_code=' . urlencode(substr($this->article->tracking_code, 0, -1)))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/articles?title=Biomedical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?issue=Clinical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?search=' . urlencode($this->article->tracking_code))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?search=Biomedical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?search=Clinical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/admin/articles?author_id={$this->author->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles/filter-options')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->author->id, 'name' => $this->author->name]);
    }

    public function test_only_publisher_or_global_admin_can_assign_production(): void
    {
        $publisherRole = Role::create(['name' => 'publisher', 'display_name' => 'Publisher', 'is_system' => true]);
        $copyEditorRole = Role::create(['name' => 'copy_editor', 'display_name' => 'Copy Editor', 'is_system' => true]);
        $publisherRole->permissions()->sync(Permission::where('name', 'articles.approve')->pluck('id'));
        $publisher = User::factory()->create(['role_id' => $publisherRole->id]);
        $copyEditor = User::factory()->create(['role_id' => $copyEditorRole->id]);
        $publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);
        $this->article->update(['status' => ArticleStatus::ACCEPTED]);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$this->article->id}/production-assignments", [
            'user_id' => $copyEditor->id,
            'role' => 'copy_editor',
        ])->assertForbidden();

        Sanctum::actingAs($publisher);
        $this->postJson("/api/admin/articles/{$this->article->id}/production-assignments", [
            'user_id' => $copyEditor->id,
            'role' => 'copy_editor',
        ])->assertCreated();
    }

    public function test_reviewer_can_submit_review_and_editor_can_record_final_decision(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/submit-review", [
            'scorecard' => ['originality' => 4, 'methodology' => 5],
            'recommendation' => 'accept',
            'comments_for_author' => 'Strong paper.',
        ])->assertStatus(200);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$this->article->id}/final-decision", [
            'decision' => 'accepted',
            'decision_source' => 'reviewer_recommendation',
            'comments_for_author' => 'Accepted.',
        ])->assertStatus(201)
            ->assertJsonPath('article.status', ArticleStatus::ACCEPTED);
    }

    public function test_author_final_review_moves_proofreading_article_to_ready_for_publication_once(): void
    {
        $this->article->update(['status' => ArticleStatus::PROOFREADING]);

        Sanctum::actingAs($this->author);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::READY_FOR_PUBLICATION)
            ->assertJsonPath('article.can_author_final_review', false);

        $this->assertDatabaseHas('articles', [
            'id' => $this->article->id,
            'status' => ArticleStatus::READY_FOR_PUBLICATION,
            'author_final_approved_by' => $this->author->id,
        ]);
        $this->assertNotNull($this->article->fresh()->author_final_approved_at);

        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Author final approval is available only during proofreading.');
    }

    public function test_author_final_review_blocks_wrong_state_wrong_user_and_duplicates(): void
    {
        $otherAuthor = User::factory()->create(['role_id' => $this->author->role_id]);
        ArticleAuthor::create([
            'article_id' => $this->article->id,
            'user_id' => $otherAuthor->id,
            'co_author_name' => $otherAuthor->name,
            'co_author_email' => $otherAuthor->email,
            'author_order' => 2,
            'is_owner' => false,
            'is_corresponding' => false,
            'can_edit' => false,
        ]);

        Sanctum::actingAs($this->author);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Author final approval is available only during proofreading.');

        $this->article->update(['status' => ArticleStatus::PROOFREADING]);

        Sanctum::actingAs($otherAuthor);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertForbidden();

        Sanctum::actingAs($this->author);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertOk();
        Article::whereKey($this->article->id)->update(['status' => ArticleStatus::PROOFREADING]);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This manuscript has already been approved for final review.');
    }

    public function test_sub_editor_recommendation_and_reviewer_acceptance(): void
    {
        Sanctum::actingAs($this->editor);
        $subEditorAssignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-sub-editor", [
            'sub_editor_id' => $this->subEditor->id,
        ])->json('assignment.id');

        $reviewerAssignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        Sanctum::actingAs($this->subEditor);
        $this->postJson("/api/admin/sub-editor-assignments/{$subEditorAssignmentId}/submit-recommendation", [
            'recommendation' => 'minor_revision',
            'comments' => 'Needs a clearer methods section.',
            'internal_notes' => 'Useful but needs polish.',
        ])->assertStatus(200)
            ->assertJsonPath('assignment.status', 'completed');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$reviewerAssignmentId}/accept")
            ->assertStatus(200)
            ->assertJsonPath('assignment.status', 'accepted');
    }

    public function test_reviewer_invitation_state_is_exposed_and_deduplicated(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertCreated()
            ->assertJsonPath('assignment.invitation_state', 'invited')
            ->json('assignment.id');

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This reviewer has already been invited or assigned for this article.');

        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonPath('article.reviewer_assignments.0.invitation_state', 'invited');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/accept")
            ->assertOk()
            ->assertJsonPath('assignment.invitation_state', 'accepted');

        Sanctum::actingAs($this->editor);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonPath('article.reviewer_assignments.0.invitation_state', 'accepted');
    }

    public function test_workflow_context_hides_confidential_notes_and_audit_logs_from_author(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/submit-review", [
            'scorecard' => ['originality' => 4, 'methodology' => 5],
            'recommendation' => 'minor_revision',
            'comments_for_author' => 'Please clarify the methods.',
            'confidential_comments' => 'Confidential editor-only concern.',
        ])->assertStatus(200);

        Sanctum::actingAs($this->author);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonPath('article.audit_logs', [])
            ->assertJsonMissing(['confidential_comments' => 'Confidential editor-only concern.']);

        Sanctum::actingAs($this->editor);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonFragment(['confidential_comments' => 'Confidential editor-only concern.'])
            ->assertJsonPath('article.audit_logs.0.article_id', $this->article->id);
    }

    public function test_publisher_issue_creation_and_article_publication(): void
    {
        Sanctum::actingAs($this->admin);

        $issueId = $this->postJson('/api/admin/issues', [
            'magazine_id' => $this->magazine->id,
            'volume_number' => 1,
            'issue_number' => 1,
            'special_title' => 'Launch Issue',
        ])->assertStatus(201)
            ->json('issue.id');

        $this->article->update(['status' => ArticleStatus::READY_FOR_PUBLICATION]);

        $this->postJson("/api/admin/articles/{$this->article->id}/publish", [
            'magazine_issue_id' => $issueId,
            'published_year' => 2026,
            'published_month' => 'June',
            'page_start' => 1,
            'page_end' => 12,
        ])->assertStatus(200)
            ->assertJsonPath('article.status', ArticleStatus::PUBLISHED);
    }

    public function test_editor_can_send_reminder_to_reviewer(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        $assignmentBefore = \App\Models\ReviewerAssignment::findOrFail($assignmentId);
        $oldHash = $assignmentBefore->invite_token_hash;

        $response = $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/remind");
        $response->assertStatus(200);

        $assignmentAfter = \App\Models\ReviewerAssignment::findOrFail($assignmentId);
        $this->assertNotEmpty($assignmentAfter->invite_token_hash);
        $this->assertNotEquals($oldHash, $assignmentAfter->invite_token_hash);
    }
}
