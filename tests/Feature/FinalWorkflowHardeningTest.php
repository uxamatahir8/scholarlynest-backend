<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Constants\SystemRoles;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\MagazineIssue;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinalWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    private array $roles = [];
    private Magazine $magazine;
    private Magazine $otherMagazine;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_merge(SystemRoles::names(), ['admin']) as $roleName) {
            $this->roles[$roleName] = Role::create([
                'name' => $roleName,
                'display_name' => Str::headline($roleName),
                'is_system' => true,
            ]);
        }

        foreach ([
            'articles.view-own',
            'articles.create',
            'articles.edit-own',
            'articles.approve',
            'articles.manage-assets',
            'articles.delete-own',
            'magazines.view-any',
            'magazines.view-own',
            'magazines.edit',
            'magazines.delete',
            'roles.manage',
            'roles.view-any',
            'settings.manage',
            'footer.manage',
        ] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], [
                'module' => Str::before($permissionName, '.'),
                'description' => $permissionName,
            ]);
        }

        $this->roles['super_admin']->permissions()->sync(Permission::pluck('id'));
        $this->roles['admin']->permissions()->sync(Permission::pluck('id'));
        $this->roles['author']->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.create', 'articles.edit-own', 'articles.manage-assets'])->pluck('id'));
        $this->roles['editor']->permissions()->sync(Permission::whereIn('name', ['magazines.view-any', 'magazines.edit', 'articles.view-own', 'articles.approve', 'articles.manage-assets'])->pluck('id'));
        $this->roles['sub_editor']->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.manage-assets'])->pluck('id'));
        $this->roles['reviewer']->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.manage-assets'])->pluck('id'));
        $this->roles['publisher']->permissions()->sync(Permission::whereIn('name', ['magazines.view-own', 'articles.view-own', 'articles.approve'])->pluck('id'));
        $this->roles['copy_editor']->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.manage-assets'])->pluck('id'));
        $this->roles['proofreader']->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.manage-assets'])->pluck('id'));

        $this->magazine = Magazine::create([
            'title' => 'Hardening Journal',
            'slug' => 'hardening-journal',
            'description' => 'QA journal',
        ]);
        $this->otherMagazine = Magazine::create([
            'title' => 'Unrelated Journal',
            'slug' => 'unrelated-journal',
            'description' => 'Other journal',
        ]);
    }

    public function test_only_super_admin_can_delete_records(): void
    {
        foreach (['admin', 'author', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'] as $roleName) {
            $tag = Tag::create(['magazine_id' => $this->magazine->id, 'name' => 'Delete Guard ' . $roleName]);
            Sanctum::actingAs($this->user($roleName));

            $this->deleteJson("/api/admin/tags/{$tag->id}")->assertForbidden();
            $this->assertDatabaseHas('tags', ['id' => $tag->id]);
        }

        $tag = Tag::create(['magazine_id' => $this->magazine->id, 'name' => 'Super Delete']);
        Sanctum::actingAs($this->user('super_admin'));

        $this->deleteJson("/api/admin/tags/{$tag->id}")->assertOk();
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_admin_role_is_not_allowed_to_delete_even_with_delete_permissions(): void
    {
        $magazine = Magazine::create([
            'title' => 'Admin Delete Blocked',
            'slug' => 'admin-delete-blocked',
            'description' => 'Delete test',
        ]);

        Sanctum::actingAs($this->user('admin'));

        $this->deleteJson("/api/admin/magazines/{$magazine->id}")->assertForbidden();
        $this->assertDatabaseHas('magazines', ['id' => $magazine->id]);
    }

    public function test_editor_can_create_page_only_for_assigned_magazine_with_ownership_metadata(): void
    {
        $editor = $this->user('editor');
        $editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        Sanctum::actingAs($editor);

        $pageId = $this->postJson("/api/admin/magazines/{$this->magazine->id}/pages", [
            'title' => 'Editorial Policy',
            'content' => 'Policy body',
            'sort_order' => 3,
        ])->assertStatus(211)
            ->assertJsonPath('page.created_by', $editor->id)
            ->assertJsonPath('page.created_by_role', 'editor')
            ->assertJsonPath('page.is_editor_created', true)
            ->json('page.id');

        $this->assertDatabaseHas('magazine_pages', [
            'id' => $pageId,
            'created_by' => $editor->id,
            'created_by_role' => 'editor',
            'is_editor_created' => true,
        ]);

        $this->postJson("/api/admin/magazines/{$this->otherMagazine->id}/pages", [
            'title' => 'Should Fail',
            'content' => 'Nope',
        ])->assertForbidden();
    }

    public function test_editor_can_edit_only_own_editor_created_pages_and_cannot_delete_pages(): void
    {
        $editor = $this->user('editor');
        $otherEditor = $this->user('editor');
        $editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $otherEditor->magazines()->attach($this->magazine->id, ['role' => 'editor']);

        $ownPage = MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Own Page',
            'slug' => 'own-page',
            'content' => 'Body',
            'created_by' => $editor->id,
            'created_by_role' => 'editor',
            'is_editor_created' => true,
        ]);
        $otherPage = MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Other Page',
            'slug' => 'other-page',
            'content' => 'Body',
            'created_by' => $otherEditor->id,
            'created_by_role' => 'editor',
            'is_editor_created' => true,
        ]);
        $superPage = MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Super Page',
            'slug' => 'super-page',
            'content' => 'Body',
            'created_by' => $this->user('super_admin')->id,
            'created_by_role' => 'super_admin',
            'is_editor_created' => false,
        ]);

        Sanctum::actingAs($editor);

        $this->putJson("/api/admin/magazines/{$this->magazine->id}/pages/{$ownPage->id}", [
            'title' => 'Own Page Updated',
            'content' => 'Updated',
            'sort_order' => 2,
        ])->assertOk();

        $this->putJson("/api/admin/magazines/{$this->magazine->id}/pages/{$otherPage->id}", [
            'title' => 'Other Updated',
            'content' => 'Updated',
        ])->assertForbidden();

        $this->putJson("/api/admin/magazines/{$this->magazine->id}/pages/{$superPage->id}", [
            'title' => 'Super Updated',
            'content' => 'Updated',
        ])->assertForbidden();

        $this->deleteJson("/api/admin/magazines/{$this->magazine->id}/pages/{$ownPage->id}")->assertForbidden();
        $this->assertDatabaseHas('magazine_pages', ['id' => $ownPage->id]);
    }

    public function test_sub_editor_cannot_access_magazine_page_management(): void
    {
        Sanctum::actingAs($this->user('sub_editor'));

        $this->postJson("/api/admin/magazines/{$this->magazine->id}/pages", [
            'title' => 'Sub Editor Page',
            'content' => 'Not allowed',
        ])->assertForbidden();
    }

    public function test_editor_cannot_create_or_normally_edit_articles(): void
    {
        $editor = $this->user('editor');
        $editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        Sanctum::actingAs($editor);

        $this->postJson('/api/articles', $this->articlePayload($editor))->assertForbidden();

        $article = $this->article($this->user('author'), ArticleStatus::SUBMITTED);
        $this->putJson("/api/admin/articles/{$article->id}", [
            'magazine_id' => $this->magazine->id,
            'title' => 'Editor Normal Edit Blocked',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
        ])->assertForbidden();
    }

    public function test_author_can_edit_only_drafts_and_requested_revisions(): void
    {
        $author = $this->user('author');
        Sanctum::actingAs($author);

        $draft = $this->article($author, ArticleStatus::DRAFT);
        $this->putJson("/api/admin/articles/{$draft->id}", [
            'magazine_id' => $this->magazine->id,
            'title' => 'Draft Edited',
            'abstract' => 'Updated abstract',
            'full_text' => 'Updated full text',
        ])->assertOk();

        $revision = $this->article($author, ArticleStatus::MINOR_REVISION_REQUIRED);
        $this->putJson("/api/admin/articles/{$revision->id}", [
            'magazine_id' => $this->magazine->id,
            'title' => 'Revision Edited',
            'abstract' => 'Updated abstract',
            'full_text' => 'Updated full text',
            'change_summary' => 'Addressed reviewer notes',
            'revision_response' => 'Updated as requested',
        ])->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::RESUBMITTED);

        foreach ([ArticleStatus::SUBMITTED, ArticleStatus::ACCEPTED, ArticleStatus::PUBLISHED] as $status) {
            $article = $this->article($author, $status);
            $this->putJson("/api/admin/articles/{$article->id}", [
                'magazine_id' => $this->magazine->id,
                'title' => 'Locked Edit ' . $status,
                'abstract' => 'Updated abstract',
                'full_text' => 'Updated full text',
            ])->assertStatus(422);
        }
    }

    public function test_reviewer_and_production_roles_cannot_publish_articles(): void
    {
        $article = $this->article($this->user('author'), ArticleStatus::READY_FOR_PUBLICATION);

        foreach (['reviewer', 'copy_editor', 'proofreader'] as $roleName) {
            Sanctum::actingAs($this->user($roleName));

            $this->postJson("/api/admin/articles/{$article->id}/publish", [
                'published_year' => now()->year,
                'published_month' => 'June',
            ])->assertForbidden();
        }
    }

    public function test_rbac_permission_sync_filters_delete_permissions_from_custom_roles(): void
    {
        $customRole = Role::create([
            'name' => 'custom_manager',
            'display_name' => 'Custom Manager',
            'is_system' => false,
        ]);

        Sanctum::actingAs($this->user('admin'));

        $this->postJson("/api/admin/rbac/roles/{$customRole->id}/permissions", [
            'permissions' => ['articles.view-own', 'articles.delete-own', 'magazines.delete'],
        ])->assertOk();

        $customRole->refresh()->load('permissions');
        $this->assertTrue($customRole->permissions->contains('name', 'articles.view-own'));
        $this->assertFalse($customRole->permissions->contains('name', 'articles.delete-own'));
        $this->assertFalse($customRole->permissions->contains('name', 'magazines.delete'));
    }

    public function test_direct_article_and_workflow_fetches_are_scoped_to_allowed_records(): void
    {
        $author = $this->user('author');
        $otherAuthor = $this->user('author');
        $ownedArticle = $this->article($author, ArticleStatus::DRAFT);
        $otherArticle = $this->article($otherAuthor, ArticleStatus::SUBMITTED);

        Sanctum::actingAs($author);
        $this->getJson("/api/admin/articles/{$ownedArticle->id}")->assertOk();
        $this->getJson("/api/admin/articles/{$otherArticle->id}")->assertForbidden();

        $editor = $this->user('editor');
        $editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $unassignedMagazineArticle = $this->articleForMagazine($otherAuthor, $this->otherMagazine, ArticleStatus::SUBMITTED);

        Sanctum::actingAs($editor);
        $this->getJson("/api/admin/articles/{$unassignedMagazineArticle->id}")->assertForbidden();

        $subEditor = $this->user('sub_editor');
        Sanctum::actingAs($subEditor);
        $this->getJson("/api/admin/articles/{$ownedArticle->id}/workflow")->assertForbidden();

        $reviewer = $this->user('reviewer');
        $confidentialFile = ArticleFile::create([
            'article_id' => $ownedArticle->id,
            'uploaded_by' => $editor->id,
            'file_type' => ArticleFile::REVIEWED_MANUSCRIPT,
            'visibility' => 'reviewer_editor',
            'file_path' => 'storage/article-files/test/reviewed.pdf',
            'original_name' => 'reviewed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 128,
        ]);

        Sanctum::actingAs($reviewer);
        $this->getJson("/api/articles/files/{$confidentialFile->id}/download")->assertForbidden();
    }

    public function test_editor_admin_magazine_reads_are_assigned_and_page_owner_scoped(): void
    {
        $editor = $this->user('editor');
        $otherEditor = $this->user('editor');
        $editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $otherEditor->magazines()->attach($this->magazine->id, ['role' => 'editor']);

        $ownPage = MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Own Editorial Page',
            'slug' => 'own-editorial-page',
            'content' => 'Own body',
            'created_by' => $editor->id,
            'created_by_role' => 'editor',
            'is_editor_created' => true,
        ]);
        $otherPage = MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Other Editorial Page',
            'slug' => 'other-editorial-page',
            'content' => 'Other body',
            'created_by' => $otherEditor->id,
            'created_by_role' => 'editor',
            'is_editor_created' => true,
        ]);
        $superPage = MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Super Page',
            'slug' => 'super-page-visible-to-admin-only',
            'content' => 'Super body',
            'created_by' => $this->user('super_admin')->id,
            'created_by_role' => 'super_admin',
            'is_editor_created' => false,
        ]);

        Sanctum::actingAs($editor);

        $this->getJson('/api/admin/magazines?per_page=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->magazine->id);

        $this->getJson("/api/admin/magazines/{$this->otherMagazine->slug}")->assertForbidden();

        $response = $this->getJson("/api/admin/magazines/{$this->magazine->slug}")
            ->assertOk()
            ->json();

        $pageIds = collect($response['pages'])->pluck('id')->all();
        $this->assertContains($ownPage->id, $pageIds);
        $this->assertNotContains($otherPage->id, $pageIds);
        $this->assertNotContains($superPage->id, $pageIds);
    }

    public function test_publisher_article_issue_and_query_access_is_publication_scoped(): void
    {
        $publisher = $this->user('publisher');
        $publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);

        $accepted = $this->article($this->user('author'), ArticleStatus::ACCEPTED);
        $ready = $this->article($this->user('author'), ArticleStatus::READY_FOR_PUBLICATION);
        $submitted = $this->article($this->user('author'), ArticleStatus::SUBMITTED);
        $otherAccepted = $this->articleForMagazine($this->user('author'), $this->otherMagazine, ArticleStatus::ACCEPTED);
        $otherIssue = MagazineIssue::create([
            'magazine_id' => $this->otherMagazine->id,
            'volume_number' => '1',
            'issue_number' => '1',
            'issue_year' => 2026,
            'status' => 'draft',
            'is_published' => false,
        ]);

        Sanctum::actingAs($publisher);

        $articleIds = collect($this->getJson('/api/admin/articles?per_page=25')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($accepted->id, $articleIds);
        $this->assertContains($ready->id, $articleIds);
        $this->assertNotContains($submitted->id, $articleIds);
        $this->assertNotContains($otherAccepted->id, $articleIds);

        $this->getJson('/api/admin/articles?status=submitted&per_page=25')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/admin/articles?magazine_id={$this->otherMagazine->id}&per_page=25")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/admin/issues/{$otherIssue->id}")->assertForbidden();
        $this->getJson("/api/admin/issues/eligible-articles?magazine_id={$this->otherMagazine->id}")->assertForbidden();
    }

    public function test_assignment_dashboards_return_only_current_users_assigned_records(): void
    {
        $editor = $this->user('editor');
        $article = $this->article($this->user('author'), ArticleStatus::UNDER_REVIEW);
        $otherArticle = $this->article($this->user('author'), ArticleStatus::UNDER_REVIEW);

        $subEditor = $this->user('sub_editor');
        $otherSubEditor = $this->user('sub_editor');
        $ownSubAssignment = SubEditorAssignment::create([
            'article_id' => $article->id,
            'sub_editor_id' => $subEditor->id,
            'assigned_by' => $editor->id,
            'status' => 'assigned',
        ]);
        SubEditorAssignment::create([
            'article_id' => $otherArticle->id,
            'sub_editor_id' => $otherSubEditor->id,
            'assigned_by' => $editor->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($subEditor);
        $this->getJson('/api/admin/my-sub-editor-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownSubAssignment->id);

        $reviewer = $this->user('reviewer');
        $otherReviewer = $this->user('reviewer');
        $ownReviewAssignment = ReviewerAssignment::create([
            'article_id' => $article->id,
            'reviewer_id' => $reviewer->id,
            'assigned_by' => $editor->id,
            'status' => 'assigned',
        ]);
        ReviewerAssignment::create([
            'article_id' => $otherArticle->id,
            'reviewer_id' => $otherReviewer->id,
            'assigned_by' => $editor->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($reviewer);
        $this->getJson('/api/admin/my-reviewer-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownReviewAssignment->id);

        $copyEditor = $this->user('copy_editor');
        $proofreader = $this->user('proofreader');
        $ownProductionAssignment = ProductionAssignment::create([
            'article_id' => $article->id,
            'user_id' => $copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $editor->id,
            'status' => 'assigned',
        ]);
        ProductionAssignment::create([
            'article_id' => $otherArticle->id,
            'user_id' => $proofreader->id,
            'role' => 'proofreader',
            'assigned_by' => $editor->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($copyEditor);
        $this->getJson('/api/admin/my-production-assignments?role=copy_editor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownProductionAssignment->id);
        $this->getJson('/api/admin/my-production-assignments?role=proofreader')
            ->assertForbidden();
    }

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => $this->roles[$roleName]->id]);
    }

    private function article(User $author, string $status): Article
    {
        return $this->articleForMagazine($author, $this->magazine, $status);
    }

    private function articleForMagazine(User $author, Magazine $magazine, string $status): Article
    {
        return Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $author->id,
            'title' => 'Hardening Article ' . $status . ' ' . uniqid(),
            'slug' => 'hardening-article-' . Str::slug($status) . '-' . uniqid(),
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }

    private function articlePayload(User $user): array
    {
        return [
            'magazine_id' => $this->magazine->id,
            'title' => 'Submitted Manuscript',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'authors' => [[
                'name' => $user->name,
                'email' => $user->email,
                'affiliation' => 'University',
                'author_order' => 1,
                'is_owner' => true,
                'is_corresponding' => true,
                'can_edit' => true,
                'create_account' => false,
            ]],
        ];
    }
}
