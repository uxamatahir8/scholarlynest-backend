<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $publisher;
    private User $copyEditor;
    private User $otherCopyEditor;
    private User $proofreader;
    private User $author;
    private Magazine $magazine;
    private Magazine $otherMagazine;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $publisherRole = Role::create(['name' => 'publisher', 'display_name' => 'Publisher', 'is_system' => true]);
        $copyEditorRole = Role::create(['name' => 'copy_editor', 'display_name' => 'Copy Editor', 'is_system' => true]);
        $proofreaderRole = Role::create(['name' => 'proofreader', 'display_name' => 'Proofreader', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        Permission::firstOrCreate(
            ['name' => 'articles.view-own'],
            ['module' => 'articles', 'description' => 'articles.view-own']
        );
        $viewPermissionIds = Permission::whereIn('name', ['articles.view-own'])->pluck('id');
        $publisherRole->permissions()->sync($viewPermissionIds);
        $copyEditorRole->permissions()->sync($viewPermissionIds);
        $proofreaderRole->permissions()->sync($viewPermissionIds);

        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->publisher = User::factory()->create(['role_id' => $publisherRole->id]);
        $this->copyEditor = User::factory()->create(['role_id' => $copyEditorRole->id]);
        $this->otherCopyEditor = User::factory()->create(['role_id' => $copyEditorRole->id]);
        $this->proofreader = User::factory()->create(['role_id' => $proofreaderRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'Production Magazine',
            'slug' => 'production-magazine',
            'description' => 'Production test magazine',
        ]);
        $this->otherMagazine = Magazine::create([
            'title' => 'Other Production Magazine',
            'slug' => 'other-production-magazine',
            'description' => 'Other production test magazine',
        ]);

        $this->publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);
        $this->copyEditor->magazines()->attach($this->magazine->id, ['role' => 'copy_editor']);
        $this->otherCopyEditor->magazines()->attach($this->magazine->id, ['role' => 'copy_editor']);
        $this->proofreader->magazines()->attach($this->magazine->id, ['role' => 'proofreader']);
    }

    public function test_copy_editor_production_dashboard_is_scoped_to_assigned_user_and_role(): void
    {
        $owned = $this->article('Copy Edited Article', ArticleStatus::COPY_EDITING, $this->magazine);
        $proofTask = $this->article('Proof Task Article', ArticleStatus::PROOFREADING, $this->magazine);
        $other = $this->article('Other Copy Article', ArticleStatus::COPY_EDITING, $this->magazine);

        ProductionAssignment::create([
            'article_id' => $owned->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
            'due_date' => now()->subDay(),
        ]);
        ProductionAssignment::create([
            'article_id' => $proofTask->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'proofreader',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);
        ProductionAssignment::create([
            'article_id' => $other->id,
            'user_id' => $this->otherCopyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->copyEditor);

        $this->getJson('/api/admin/my-production-assignments?role=copy_editor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Copy Edited Article')
            ->assertJsonPath('data.0.role', 'copy_editor')
            ->assertJsonMissingPath('data.0.user_id')
            ->assertJsonMissingPath('data.0.user')
            ->assertJsonMissingPath('data.0.files')
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_copy_editor_sees_only_files_from_latest_accepted_submission(): void
    {
        $article = $this->article('Latest Accepted Files', ArticleStatus::COPY_EDITING, $this->magazine);
        $initial = ArticleVersion::create([
            'article_id' => $article->id,
            'created_by' => $this->author->id,
            'version_number' => 1,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
        ]);
        $latest = ArticleVersion::create([
            'article_id' => $article->id,
            'created_by' => $this->author->id,
            'version_number' => 2,
            'revision_number' => 1,
            'label' => 'Revised Manuscript',
            'status_snapshot' => ArticleStatus::RESUBMITTED,
        ]);
        $oldFile = ArticleFile::create([
            'article_id' => $article->id, 'article_version_id' => $initial->id, 'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT, 'visibility' => 'author_visible', 'file_path' => 'old.pdf',
            'original_name' => 'old.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean',
        ]);
        $latestFile = ArticleFile::create([
            'article_id' => $article->id, 'article_version_id' => $latest->id, 'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT, 'visibility' => 'author_visible', 'file_path' => 'latest.pdf',
            'original_name' => 'latest.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'scan_status' => 'clean',
        ]);
        ProductionAssignment::create([
            'article_id' => $article->id, 'user_id' => $this->copyEditor->id, 'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->copyEditor);
        $response = $this->getJson("/api/admin/articles/{$article->id}/workflow")
            ->assertOk()
            ->assertJsonFragment(['id' => $latestFile->id, 'original_name' => 'latest.pdf']);
        $this->assertStringNotContainsString('old.pdf', $response->getContent());
    }

    public function test_proofreader_production_dashboard_is_inactive(): void
    {
        $owned = $this->article('Proofread Article', ArticleStatus::PROOFREADING, $this->magazine);
        $copyTask = $this->article('Copy Task Article', ArticleStatus::COPY_EDITING, $this->magazine);

        ProductionAssignment::create([
            'article_id' => $owned->id,
            'user_id' => $this->proofreader->id,
            'role' => 'proofreader',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);
        ProductionAssignment::create([
            'article_id' => $copyTask->id,
            'user_id' => $this->proofreader->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->proofreader);

        $this->getJson('/api/admin/my-production-assignments?role=proofreader')
            ->assertStatus(422);
    }

    public function test_non_production_roles_cannot_access_production_assignment_dashboard(): void
    {
        Sanctum::actingAs($this->author);

        $this->getJson('/api/admin/my-production-assignments')->assertForbidden();
    }

    public function test_publisher_dashboard_is_scoped_to_assigned_magazines(): void
    {
        $ready = $this->article('Ready For Publisher', ArticleStatus::READY_FOR_PUBLICATION, $this->magazine);
        $published = $this->article('Recently Published', ArticleStatus::PUBLISHED, $this->magazine);
        $this->article('Other Ready', ArticleStatus::READY_FOR_PUBLICATION, $this->otherMagazine);
        $this->article('Draft Hidden', ArticleStatus::DRAFT, $this->magazine);

        $issue = MagazineIssue::create([
            'magazine_id' => $this->magazine->id,
            'volume_number' => 4,
            'issue_number' => 1,
            'status' => 'draft',
        ]);
        MagazineIssue::create([
            'magazine_id' => $this->otherMagazine->id,
            'volume_number' => 9,
            'issue_number' => 1,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->publisher);

        $this->getJson('/api/admin/publisher-dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'magazines')
            ->assertJsonPath('magazines.0.id', $this->magazine->id)
            ->assertJsonCount(1, 'ready_articles')
            ->assertJsonPath('ready_articles.0.id', $ready->id)
            ->assertJsonCount(1, 'published_articles')
            ->assertJsonPath('published_articles.0.id', $published->id)
            ->assertJsonCount(1, 'issues')
            ->assertJsonPath('issues.0.id', $issue->id);
    }

    public function test_non_publishers_cannot_access_publisher_dashboard(): void
    {
        Sanctum::actingAs($this->copyEditor);

        $this->getJson('/api/admin/publisher-dashboard')->assertForbidden();
    }

    public function test_super_admin_can_view_all_production_assignments(): void
    {
        ProductionAssignment::create([
            'article_id' => $this->article('Copy Admin', ArticleStatus::COPY_EDITING, $this->magazine)->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);
        ProductionAssignment::create([
            'article_id' => $this->article('Proof Admin', ArticleStatus::PROOFREADING, $this->magazine)->id,
            'user_id' => $this->proofreader->id,
            'role' => 'proofreader',
            'assigned_by' => $this->publisher->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/my-production-assignments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_copy_editor_dashboard_preview_honors_per_page_limit_and_pagination(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            ProductionAssignment::create([
                'article_id' => $this->article("Copy Task {$i}", ArticleStatus::COPY_EDITING, $this->magazine)->id,
                'user_id' => $this->copyEditor->id,
                'role' => 'copy_editor',
                'assigned_by' => $this->publisher->id,
                'status' => 'pending',
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        ProductionAssignment::create([
            'article_id' => $this->article('Other Copy Hidden', ArticleStatus::COPY_EDITING, $this->magazine)->id,
            'user_id' => $this->otherCopyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->copyEditor);

        $this->getJson('/api/admin/my-production-assignments?role=copy_editor&per_page=10&page=1')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 12)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('last_page', 2);

        $this->getJson('/api/admin/my-production-assignments?role=copy_editor&per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_copy_editor_task_filters_are_constrained_and_server_side(): void
    {
        ProductionAssignment::create([
            'article_id' => $this->article('Active Copy Task', ArticleStatus::COPY_EDITING, $this->magazine)->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        ProductionAssignment::create([
            'article_id' => $this->article('Completed Copy Task', ArticleStatus::READY_FOR_PUBLICATION, $this->magazine)->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($this->copyEditor);

        $this->getJson('/api/admin/my-production-assignments?role=copy_editor&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Active Copy Task');

        $this->getJson('/api/admin/my-production-assignments?role=copy_editor&status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article.title', 'Completed Copy Task');

        $this->getJson('/api/admin/my-production-assignments?role=copy_editor&status=all')->assertUnprocessable();
    }

    public function test_copy_editor_can_complete_only_own_copyediting_assignment(): void
    {
        $own = ProductionAssignment::create([
            'article_id' => $this->article('Own Copy Complete', ArticleStatus::COPY_EDITING, $this->magazine)->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        $other = ProductionAssignment::create([
            'article_id' => $this->article('Other Copy Complete', ArticleStatus::COPY_EDITING, $this->magazine)->id,
            'user_id' => $this->otherCopyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        $proofreaderRoleMismatch = ProductionAssignment::create([
            'article_id' => $this->article('Wrong Role Complete', ArticleStatus::PROOFREADING, $this->magazine)->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'proofreader',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->copyEditor);

        $this->postJson("/api/admin/production-assignments/{$other->id}/complete")->assertForbidden();
        $this->postJson("/api/admin/production-assignments/{$proofreaderRoleMismatch->id}/complete")->assertForbidden();

        $this->postJson("/api/admin/production-assignments/{$own->id}/complete")
            ->assertOk()
            ->assertJsonPath('assignment.status', 'completed');

        $this->assertDatabaseHas('production_assignments', [
            'id' => $own->id,
            'status' => 'completed',
        ]);
    }

    public function test_observer_mode_can_list_copy_editor_tasks_but_cannot_complete_them(): void
    {
        $assignment = ProductionAssignment::create([
            'article_id' => $this->article('Observed Copy Task', ArticleStatus::COPY_EDITING, $this->magazine)->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->publisher->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/admin/my-production-assignments?role=copy_editor&observer_user_id={$this->copyEditor->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignment->id);

        $this->postJson("/api/admin/production-assignments/{$assignment->id}/complete", [
            'observer_user_id' => $this->copyEditor->id,
        ])->assertUnprocessable();
    }

    private function article(string $title, string $status, Magazine $magazine): Article
    {
        return Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $this->author->id,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . uniqid(),
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }
}
