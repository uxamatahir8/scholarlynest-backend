<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CmsAdminPageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_cms_endpoint_does_not_return_inactive_pages(): void
    {
        CmsPage::create([
            'slug' => 'privacy',
            'title' => 'Private Draft',
            'content_text' => 'Inactive internal draft',
            'content_html' => '<p>Inactive internal draft</p>',
            'is_active' => false,
        ]);

        $this->getJson('/api/cms/privacy')->assertNotFound();
    }

    public function test_authorized_admin_cms_endpoint_returns_inactive_page_allow_list(): void
    {
        $user = $this->userWithPermissions('content_manager', ['settings.manage']);
        $page = CmsPage::create([
            'slug' => 'privacy',
            'title' => 'Private Draft',
            'content_text' => 'Inactive internal draft',
            'content_html' => '<p>Inactive internal draft</p>',
            'is_active' => false,
            'seo_title' => 'Private SEO',
            'seo_description' => 'Private SEO description',
            'seo_keywords' => 'private',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/cms/privacy')
            ->assertOk()
            ->assertJsonPath('page.id', $page->id)
            ->assertJsonPath('page.slug', 'privacy')
            ->assertJsonPath('page.is_active', false)
            ->assertJsonPath('page.content_html', '<p>Inactive internal draft</p>')
            ->assertJsonMissing(['password'])
            ->assertJsonMissing(['permissions'])
            ->assertJsonMissing(['token']);
    }

    public function test_seo_cms_user_can_read_inactive_page_for_metadata_management(): void
    {
        $user = $this->userWithPermissions('cms_seo', ['seo.cms-pages']);
        CmsPage::create([
            'slug' => 'terms',
            'title' => 'Inactive Terms',
            'content_text' => 'Inactive terms',
            'content_html' => '<p>Inactive terms</p>',
            'is_active' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/cms/terms')
            ->assertOk()
            ->assertJsonPath('page.slug', 'terms')
            ->assertJsonPath('page.is_active', false);
    }

    public function test_user_without_cms_permissions_cannot_read_admin_cms_page(): void
    {
        $role = Role::create(['name' => 'author']);
        $user = User::factory()->create(['role_id' => $role->id]);

        CmsPage::create([
            'slug' => 'privacy',
            'title' => 'Private Draft',
            'content_text' => 'Inactive internal draft',
            'content_html' => '<p>Inactive internal draft</p>',
            'is_active' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/cms/privacy')->assertForbidden();
    }

    private function userWithPermissions(string $roleName, array $permissions): User
    {
        $role = Role::create(['name' => $roleName]);

        $permissionIds = collect($permissions)
            ->map(fn (string $permission) => Permission::firstOrCreate(
                ['name' => $permission],
                ['module' => 'cms', 'description' => $permission]
            )->id)
            ->all();

        $role->permissions()->sync($permissionIds);

        return User::factory()->create(['role_id' => $role->id]);
    }
}
