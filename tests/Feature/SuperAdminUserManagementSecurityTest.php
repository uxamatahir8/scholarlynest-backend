<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Str;

class SuperAdminUserManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'author', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'] as $roleName) {
            $this->roles[$roleName] = Role::create([
                'name' => $roleName,
                'display_name' => Str::headline($roleName),
                'is_system' => true,
            ]);
        }
    }

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => $this->roles[$roleName]->id]);
    }

    /**
     * Test that Super Admin has full authorized access to User Management APIs.
     */
    public function test_super_admin_can_access_all_rbac_api_endpoints(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $this->getJson('/api/admin/rbac/users')->assertOk();
        $this->getJson('/api/admin/rbac/roles')->assertOk();
        $this->getJson('/api/admin/rbac/permissions')->assertOk();
        $this->getJson('/api/admin/rbac/settings/registration-role')->assertOk();
    }

    /**
     * Test that no other roles (including Legacy Admin) can query or edit User Management APIs.
     */
    public function test_all_other_roles_cannot_access_rbac_api_endpoints(): void
    {
        $nonSuperAdminRoles = ['admin', 'author', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'];

        foreach ($nonSuperAdminRoles as $roleName) {
            Sanctum::actingAs($this->user($roleName));

            $this->getJson('/api/admin/rbac/users')->assertForbidden();
            $this->getJson('/api/admin/rbac/roles')->assertForbidden();
            $this->getJson('/api/admin/rbac/permissions')->assertForbidden();
            $this->getJson('/api/admin/rbac/settings/registration-role')->assertForbidden();
        }
    }
}
