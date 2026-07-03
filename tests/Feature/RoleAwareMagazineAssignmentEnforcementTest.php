<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAwareMagazineAssignmentEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private array $roles = [];
    private User $superAdmin;
    private User $author;
    private Magazine $magazineA;
    private Magazine $magazineB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'author', 'editor', 'magazine_editor', 'publisher', 'proofreader'] as $roleName) {
            $this->roles[$roleName] = Role::create([
                'name' => $roleName,
                'display_name' => Str::headline($roleName),
                'is_system' => true,
            ]);
        }

        foreach (['articles.view-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['module' => 'articles', 'description' => $permission]);
        }

        foreach (['editor', 'magazine_editor', 'publisher', 'proofreader'] as $roleName) {
            $this->roles[$roleName]->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        }

        $this->superAdmin = $this->user('super_admin');
        $this->author = $this->user('author');

        $this->magazineA = $this->magazine('Assigned Journal');
        $this->magazineB = $this->magazine('Blocked Journal');
    }

    public function test_editor_and_magazine_editor_are_limited_to_assigned_magazines(): void
    {
        foreach (['editor', 'magazine_editor'] as $roleName) {
            $editor = $this->user($roleName);
            $editor->magazines()->attach($this->magazineA->id, ['role' => 'editor']);

            $allowedArticle = $this->article($this->magazineA, ArticleStatus::UNDER_REVIEW, "{$roleName} allowed");
            $blockedArticle = $this->article($this->magazineB, ArticleStatus::UNDER_REVIEW, "{$roleName} blocked");

            Sanctum::actingAs($editor);

            $this->getJson('/api/admin/articles')
                ->assertOk()
                ->assertJsonFragment(['title' => $allowedArticle->title])
                ->assertJsonMissing(['title' => $blockedArticle->title]);

            $this->getJson("/api/admin/articles/{$allowedArticle->id}/workflow")->assertOk();
            $this->getJson("/api/admin/articles/{$blockedArticle->id}")->assertForbidden();
            $this->getJson("/api/admin/articles/{$blockedArticle->id}/workflow")->assertForbidden();
        }
    }

    public function test_publisher_is_limited_to_assigned_magazine_publication_work(): void
    {
        $publisher = $this->user('publisher');
        $publisher->magazines()->attach($this->magazineA->id, ['role' => 'publisher']);

        $allowedIssue = MagazineIssue::create([
            'magazine_id' => $this->magazineA->id,
            'volume_number' => 1,
            'issue_number' => 1,
            'status' => 'draft',
        ]);
        $blockedIssue = MagazineIssue::create([
            'magazine_id' => $this->magazineB->id,
            'volume_number' => 1,
            'issue_number' => 1,
            'status' => 'draft',
        ]);

        $this->article($this->magazineA, ArticleStatus::READY_FOR_PUBLICATION, 'publisher allowed');
        $this->article($this->magazineB, ArticleStatus::READY_FOR_PUBLICATION, 'publisher blocked');

        Sanctum::actingAs($publisher);

        $this->getJson('/api/admin/publisher-dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'magazines')
            ->assertJsonPath('magazines.0.id', $this->magazineA->id);

        $this->getJson('/api/admin/issues')
            ->assertOk()
            ->assertJsonFragment(['id' => $allowedIssue->id])
            ->assertJsonMissing(['id' => $blockedIssue->id]);

        $this->getJson("/api/admin/issues/{$blockedIssue->id}")->assertForbidden();
    }

    public function test_proofreader_requires_task_assignment_and_magazine_assignment(): void
    {
        $proofreader = $this->user('proofreader');
        $proofreader->magazines()->attach($this->magazineA->id, ['role' => 'proofreader']);

        $allowedArticle = $this->article($this->magazineA, ArticleStatus::PROOFREADING, 'proofreader allowed');
        $blockedArticle = $this->article($this->magazineB, ArticleStatus::PROOFREADING, 'proofreader blocked');

        ProductionAssignment::create([
            'article_id' => $allowedArticle->id,
            'user_id' => $proofreader->id,
            'role' => 'proofreader',
            'assigned_by' => $this->superAdmin->id,
            'status' => 'pending',
        ]);
        $blockedAssignment = ProductionAssignment::create([
            'article_id' => $blockedArticle->id,
            'user_id' => $proofreader->id,
            'role' => 'proofreader',
            'assigned_by' => $this->superAdmin->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($proofreader);

        $this->getJson('/api/admin/my-production-assignments?role=proofreader')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.id', $allowedArticle->id);

        $this->getJson("/api/admin/articles/{$blockedArticle->id}")->assertForbidden();
        $this->getJson("/api/admin/articles/{$blockedArticle->id}/workflow")->assertForbidden();
        $this->postJson("/api/admin/production-assignments/{$blockedAssignment->id}/complete")->assertForbidden();
    }

    public function test_proofreader_direct_file_access_requires_magazine_assignment(): void
    {
        $proofreader = $this->user('proofreader');
        $proofreader->magazines()->attach($this->magazineA->id, ['role' => 'proofreader']);

        $blockedArticle = $this->article($this->magazineB, ArticleStatus::PROOFREADING, 'proofreader file blocked');
        ProductionAssignment::create([
            'article_id' => $blockedArticle->id,
            'user_id' => $proofreader->id,
            'role' => 'proofreader',
            'assigned_by' => $this->superAdmin->id,
            'status' => 'pending',
        ]);
        $file = ArticleFile::create([
            'article_id' => $blockedArticle->id,
            'uploaded_by' => $this->superAdmin->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'visibility' => 'workflow',
            'file_path' => 'storage/article-files/blocked/manuscript.pdf',
            'original_name' => 'manuscript.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        Sanctum::actingAs($proofreader);

        $this->getJson("/api/articles/files/{$file->id}/download")->assertForbidden();
    }

    public function test_removing_magazine_assignment_removes_future_access_without_deleting_history(): void
    {
        $proofreader = $this->user('proofreader');
        $proofreader->magazines()->attach($this->magazineA->id, ['role' => 'proofreader']);

        $article = $this->article($this->magazineA, ArticleStatus::PROOFREADING, 'removed access');
        $assignment = ProductionAssignment::create([
            'article_id' => $article->id,
            'user_id' => $proofreader->id,
            'role' => 'proofreader',
            'assigned_by' => $this->superAdmin->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($proofreader);
        $this->getJson('/api/admin/my-production-assignments?role=proofreader')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->superAdmin);
        $this->patchJson("/api/admin/users/{$proofreader->id}", [
            'name' => $proofreader->name,
            'email' => $proofreader->email,
            'role_id' => $this->roles['proofreader']->id,
            'status' => 'active',
            'magazine_ids' => [$this->magazineB->id],
        ])->assertOk();

        $this->assertDatabaseHas('production_assignments', ['id' => $assignment->id]);

        Sanctum::actingAs($proofreader->fresh());
        $this->getJson('/api/admin/my-production-assignments?role=proofreader')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/articles/{$article->id}")->assertForbidden();
    }

    public function test_super_admin_retains_global_article_access(): void
    {
        $article = $this->article($this->magazineB, ArticleStatus::UNDER_REVIEW, 'super admin global');

        Sanctum::actingAs($this->superAdmin);

        $this->getJson("/api/admin/articles/{$article->id}")->assertOk();
        $this->getJson("/api/admin/articles/{$article->id}/workflow")->assertOk();
    }

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => $this->roles[$roleName]->id]);
    }

    private function magazine(string $title): Magazine
    {
        return Magazine::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . uniqid(),
            'description' => 'Role-aware test journal',
        ]);
    }

    private function article(Magazine $magazine, string $status, string $title): Article
    {
        return Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $this->author->id,
            'title' => Str::headline($title) . ' ' . uniqid(),
            'slug' => Str::slug($title) . '-' . uniqid(),
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }
}
