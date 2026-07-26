<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\SubEditorAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubEditorReviewerDeskOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private User $subEditorA;

    private User $subEditorB;

    private User $reviewerA;

    private User $reviewerB;

    private Magazine $magazine;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $subEditorRole = Role::create(['name' => 'sub_editor', 'display_name' => 'Sub Editor', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        Permission::firstOrCreate(['name' => 'articles.view-own'], ['module' => 'articles', 'description' => 'articles.view-own']);
        Permission::firstOrCreate(['name' => 'magazines.view-own'], ['module' => 'magazines', 'description' => 'magazines.view-own']);

        $editorRole->permissions()->sync(Permission::pluck('id'));
        $subEditorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));
        $reviewerRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->subEditorA = User::factory()->create(['role_id' => $subEditorRole->id]);
        $this->subEditorB = User::factory()->create(['role_id' => $subEditorRole->id]);
        $this->reviewerA = User::factory()->create(['role_id' => $reviewerRole->id]);
        $this->reviewerB = User::factory()->create(['role_id' => $reviewerRole->id]);
        $author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'Quantum Science',
            'slug' => 'quantum-science',
            'description' => 'Quantum mechanics magazine',
        ]);
    }

    public function test_sub_editor_dashboard_limits_and_scopes_to_10_assigned_items(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $article = Article::create([
                'magazine_id' => $this->magazine->id,
                'user_id' => $this->admin->id,
                'title' => "SubEditor A Article {$i}",
                'slug' => "sub-editor-a-article-{$i}",
                'abstract' => "Abstract {$i}",
                'full_text' => "Heavy text payload {$i}",
                'status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
            ]);

            SubEditorAssignment::create([
                'article_id' => $article->id,
                'sub_editor_id' => $this->subEditorA->id,
                'assigned_by' => $this->editor->id,
                'status' => 'pending',
                'due_date' => now()->addDays(5),
            ]);
        }

        // SubEditor B assignment
        $otherArticle = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'SubEditor B Article',
            'slug' => 'sub-editor-b-article',
            'abstract' => 'Abstract B',
            'full_text' => 'Heavy text payload B',
            'status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
        ]);
        SubEditorAssignment::create([
            'article_id' => $otherArticle->id,
            'sub_editor_id' => $this->subEditorB->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->subEditorA);

        $response = $this->getJson('/api/admin/my-sub-editor-assignments?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 15)
            ->assertJsonPath('per_page', 10);

        // Verify payload does NOT expose heavy full_text, files, or audit logs
        $item = $response->json('data.0');
        $this->assertArrayHasKey('primary_action', $item);
        $this->assertArrayNotHasKey('full_text', $item);
        $this->assertArrayNotHasKey('files', $item);
        $this->assertArrayNotHasKey('audit_logs', $item);
    }

    public function test_reviewer_dashboard_limits_and_scopes_to_10_assigned_items(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $article = Article::create([
                'magazine_id' => $this->magazine->id,
                'user_id' => $this->admin->id,
                'title' => "Reviewer A Article {$i}",
                'slug' => "reviewer-a-article-{$i}",
                'abstract' => "Abstract {$i}",
                'full_text' => "Heavy text payload {$i}",
                'status' => ArticleStatus::REVIEWER_ASSIGNED,
            ]);

            ReviewerAssignment::create([
                'article_id' => $article->id,
                'reviewer_id' => $this->reviewerA->id,
                'assigned_by' => $this->editor->id,
                'status' => 'pending',
                'due_date' => now()->addDays(7),
            ]);
        }

        Sanctum::actingAs($this->reviewerA);

        $response = $this->getJson('/api/admin/my-reviewer-assignments?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 12)
            ->assertJsonPath('per_page', 10);

        $item = $response->json('data.0');
        $this->assertEquals('accept_decline', $item['primary_action']);
        $this->assertArrayNotHasKey('full_text', $item);
    }

    public function test_per_page_is_capped_at_50(): void
    {
        Sanctum::actingAs($this->subEditorA);

        $this->getJson('/api/admin/my-sub-editor-assignments?per_page=100')
            ->assertOk()
            ->assertJsonPath('per_page', 50);
    }

    public function test_sub_editor_cannot_see_other_sub_editor_assigned_articles(): void
    {
        $articleA = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'SubEditor A Special Task',
            'slug' => 'sub-editor-a-special-task',
            'abstract' => 'Abstract A',
            'full_text' => 'Secret text A',
            'status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
        ]);
        SubEditorAssignment::create([
            'article_id' => $articleA->id,
            'sub_editor_id' => $this->subEditorA->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);

        $articleB = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'SubEditor B Special Task',
            'slug' => 'sub-editor-b-special-task',
            'abstract' => 'Abstract B',
            'full_text' => 'Secret text B',
            'status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
        ]);
        SubEditorAssignment::create([
            'article_id' => $articleB->id,
            'sub_editor_id' => $this->subEditorB->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->subEditorA);

        // Queue check
        $queueResponse = $this->getJson('/api/admin/my-sub-editor-assignments')
            ->assertOk();
        $ids = collect($queueResponse->json('data'))->pluck('article.id')->all();
        $this->assertContains($articleA->id, $ids);
        $this->assertNotContains($articleB->id, $ids);

        // Direct workspace URL access check
        $this->getJson("/api/admin/articles/{$articleA->id}")->assertOk();
        $this->getJson("/api/admin/articles/{$articleB->id}")->assertForbidden();
    }

    public function test_reviewer_cannot_see_other_reviewer_assignments(): void
    {
        $articleA = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'Reviewer A Manuscript',
            'slug' => 'reviewer-a-manuscript',
            'abstract' => 'Abstract A',
            'full_text' => 'Content A',
            'status' => ArticleStatus::REVIEWER_ASSIGNED,
        ]);
        $versionA = ArticleVersion::create([
            'article_id' => $articleA->id,
            'version_number' => 1,
            'created_by' => $this->admin->id,
            'status_snapshot' => ArticleStatus::REVIEWER_ASSIGNED,
            'submitted_at' => now(),
            'screening_status' => 'passed',
            'screened_at' => now(),
        ]);
        $articleA->update(['current_version_id' => $versionA->id]);
        ReviewerAssignment::create([
            'article_id' => $articleA->id,
            'article_version_id' => $versionA->id,
            'reviewer_id' => $this->reviewerA->id,
            'assigned_by' => $this->editor->id,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $articleB = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'Reviewer B Manuscript',
            'slug' => 'reviewer-b-manuscript',
            'abstract' => 'Abstract B',
            'full_text' => 'Content B',
            'status' => ArticleStatus::REVIEWER_ASSIGNED,
        ]);
        $versionB = ArticleVersion::create([
            'article_id' => $articleB->id,
            'version_number' => 1,
            'created_by' => $this->admin->id,
            'status_snapshot' => ArticleStatus::REVIEWER_ASSIGNED,
            'submitted_at' => now(),
            'screening_status' => 'passed',
            'screened_at' => now(),
        ]);
        $articleB->update(['current_version_id' => $versionB->id]);
        ReviewerAssignment::create([
            'article_id' => $articleB->id,
            'article_version_id' => $versionB->id,
            'reviewer_id' => $this->reviewerB->id,
            'assigned_by' => $this->editor->id,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($this->reviewerA);

        // Queue check
        $response = $this->getJson('/api/admin/my-reviewer-assignments')->assertOk();
        $ids = collect($response->json('data'))->pluck('article.id')->all();
        $this->assertContains($articleA->id, $ids);
        $this->assertNotContains($articleB->id, $ids);

        // Direct workspace URL access check
        $this->getJson("/api/admin/articles/{$articleA->id}")->assertOk();
        $this->getJson("/api/admin/articles/{$articleB->id}")->assertForbidden();
    }

    public function test_sub_editor_status_filters_work(): void
    {
        $article1 = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'Active Task Article',
            'slug' => 'active-task-article',
            'abstract' => 'Abstract 1',
            'full_text' => 'Full text 1',
            'status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
        ]);
        SubEditorAssignment::create([
            'article_id' => $article1->id,
            'sub_editor_id' => $this->subEditorA->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);

        $article2 = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'Completed Task Article',
            'slug' => 'completed-task-article',
            'abstract' => 'Abstract 2',
            'full_text' => 'Full text 2',
            'status' => ArticleStatus::REVIEW_IN_PROGRESS,
        ]);
        SubEditorAssignment::create([
            'article_id' => $article2->id,
            'sub_editor_id' => $this->subEditorA->id,
            'assigned_by' => $this->editor->id,
            'status' => 'completed',
            'completed_at' => now(),
            'recommendation' => 'accept',
        ]);

        Sanctum::actingAs($this->subEditorA);

        // Active
        $this->getJson('/api/admin/my-sub-editor-assignments?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Active Task Article');

        // Completed
        $this->getJson('/api/admin/my-sub-editor-assignments?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Completed Task Article');

        // Pending
        $this->getJson('/api/admin/my-sub-editor-assignments?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Active Task Article');
    }
}
