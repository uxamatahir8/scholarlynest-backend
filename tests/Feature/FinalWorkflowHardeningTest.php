<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Constants\SystemRoles;
use App\Models\Article;
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

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => $this->roles[$roleName]->id]);
    }

    private function article(User $author, string $status): Article
    {
        return Article::create([
            'magazine_id' => $this->magazine->id,
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
