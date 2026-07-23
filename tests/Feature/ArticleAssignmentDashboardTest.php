<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\SubEditorAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleAssignmentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $editor;
    private User $subEditor;
    private User $otherSubEditor;
    private User $reviewer;
    private User $otherReviewer;
    private Magazine $magazine;
    private Article $article;
    private Article $otherArticle;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $subEditorRole = Role::create(['name' => 'sub_editor', 'display_name' => 'Sub Editor', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        Permission::firstOrCreate(
            ['name' => 'articles.view-own'],
            ['module' => 'articles', 'description' => 'articles.view-own']
        );

        $editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));
        $subEditorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));
        $reviewerRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->subEditor = User::factory()->create(['role_id' => $subEditorRole->id]);
        $this->otherSubEditor = User::factory()->create(['role_id' => $subEditorRole->id]);
        $this->reviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $this->otherReviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'Dashboard Magazine',
            'slug' => 'dashboard-magazine',
            'description' => 'Dashboard test magazine',
        ]);

        foreach ([$this->editor, $this->subEditor, $this->otherSubEditor, $this->reviewer, $this->otherReviewer] as $user) {
            $user->magazines()->attach($this->magazine->id, ['role' => $user->role->name]);
        }

        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $author->id,
            'title' => 'Owned Assignment Article',
            'slug' => 'owned-assignment-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::REVIEWER_ASSIGNED,
        ]);

        $this->otherArticle = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $author->id,
            'title' => 'Other Assignment Article',
            'slug' => 'other-assignment-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
        ]);
    }

    public function test_sub_editor_assignment_dashboard_is_scoped_to_authenticated_sub_editor(): void
    {
        SubEditorAssignment::create([
            'article_id' => $this->article->id,
            'sub_editor_id' => $this->subEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        SubEditorAssignment::create([
            'article_id' => $this->otherArticle->id,
            'sub_editor_id' => $this->otherSubEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'due_date' => now()->addDays(5),
        ]);

        Sanctum::actingAs($this->subEditor);

        $this->getJson('/api/admin/my-sub-editor-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Owned Assignment Article')
            ->assertJsonPath('data.0.sub_editor_id', $this->subEditor->id);
    }

    public function test_sub_editor_desk_handles_sub_editor_recommended_and_other_statuses(): void
    {
        $recArticle = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->admin->id,
            'title' => 'Recommended Article',
            'slug' => 'recommended-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::SUB_EDITOR_RECOMMENDED,
        ]);

        SubEditorAssignment::create([
            'article_id' => $recArticle->id,
            'sub_editor_id' => $this->subEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'due_date' => now()->addDays(2),
        ]);

        Sanctum::actingAs($this->subEditor);

        $this->getJson('/api/admin/my-sub-editor-assignments')
            ->assertOk()
            ->assertJsonPath('data.0.primary_action', 'submit_recommendation');
    }

    public function test_reviewer_assignment_dashboard_is_scoped_to_authenticated_reviewer(): void
    {
        ReviewerAssignment::create([
            'article_id' => $this->article->id,
            'reviewer_id' => $this->reviewer->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'due_date' => now()->subDay(),
        ]);

        ReviewerAssignment::create([
            'article_id' => $this->otherArticle->id,
            'reviewer_id' => $this->otherReviewer->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'due_date' => now()->addDays(5),
        ]);

        Sanctum::actingAs($this->reviewer);

        $this->getJson('/api/admin/my-reviewer-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Owned Assignment Article')
            ->assertJsonPath('data.0.reviewer_id', $this->reviewer->id)
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_editor_cannot_use_personal_assignment_dashboards(): void
    {
        Sanctum::actingAs($this->editor);

        $this->getJson('/api/admin/my-sub-editor-assignments')->assertForbidden();
        $this->getJson('/api/admin/my-reviewer-assignments')->assertForbidden();
    }

    public function test_super_admin_can_view_all_assignment_dashboards(): void
    {
        SubEditorAssignment::create([
            'article_id' => $this->article->id,
            'sub_editor_id' => $this->subEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);

        SubEditorAssignment::create([
            'article_id' => $this->otherArticle->id,
            'sub_editor_id' => $this->otherSubEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/my-sub-editor-assignments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_sub_editor_can_only_see_assigned_articles(): void
    {
        SubEditorAssignment::create([
            'article_id' => $this->article->id,
            'sub_editor_id' => $this->subEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
            'due_date' => now()->addDays(3),
        ]);

        Sanctum::actingAs($this->subEditor);

        // 1. Can view their assigned article
        $this->getJson("/api/admin/articles/{$this->article->id}")
            ->assertOk();

        // 2. Cannot view the other article
        $this->getJson("/api/admin/articles/{$this->otherArticle->id}")
            ->assertForbidden();

        // 3. Main article list only returns the assigned article
        $response = $this->getJson('/api/admin/articles')
            ->assertOk();
            
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->article->id, $data[0]['id']);
    }
}
