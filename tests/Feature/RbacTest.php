<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use App\Models\ImpersonationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected Role $superAdminRole;
    protected Role $authorRole;
    protected array $systemRoles = [];
    protected User $superAdmin;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminRole = Role::create([
            'name' => 'super_admin',
            'display_name' => 'Super Admin',
            'is_system' => true
        ]);

        $this->authorRole = Role::create([
            'name' => 'author',
            'display_name' => 'Author',
            'is_system' => true
        ]);

        foreach (['admin', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'] as $roleName) {
            $this->systemRoles[$roleName] = Role::create([
                'name' => $roleName,
                'display_name' => \Illuminate\Support\Str::headline($roleName),
                'is_system' => true,
            ]);
        }

        $this->superAdmin = User::create([
            'name' => 'Admin Alice',
            'email' => 'alice@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role_id' => $this->superAdminRole->id,
            'email_verified_at' => now(),
        ]);

        $this->otherUser = User::create([
            'name' => 'Author Bob',
            'email' => 'bob@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Test that GET /api/admin/rbac/users does not return the logged-in user.
     */
    public function test_rbac_users_endpoint_excludes_logged_in_user(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->getJson('/api/admin/rbac/users');

        $response->assertStatus(200);

        // Assert that the response contains other users (Author Bob)
        $response->assertJsonFragment([
            'email' => 'bob@test.com'
        ]);

        // Assert that the response does NOT contain the logged-in user (Admin Alice)
        $response->assertJsonMissing([
            'email' => 'alice@test.com'
        ]);
    }

    public function test_system_role_permissions_cannot_be_synced(): void
    {
        Sanctum::actingAs($this->superAdmin);

        Permission::firstOrCreate(
            ['name' => 'articles.create'],
            ['module' => 'articles', 'description' => 'Create articles']
        );

        $response = $this->postJson("/api/admin/rbac/roles/{$this->authorRole->id}/permissions", [
            'permissions' => ['articles.create'],
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'System role permissions cannot be changed.');
    }

    public function test_system_role_cannot_be_renamed_or_edited(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->putJson("/api/admin/rbac/roles/{$this->authorRole->id}", [
            'name' => 'writer',
            'display_name' => 'Writer',
            'description' => 'Renamed role',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'System roles cannot be renamed or edited.');

        $this->assertDatabaseHas('roles', [
            'id' => $this->authorRole->id,
            'name' => 'author',
            'display_name' => 'Author',
        ]);
    }

    public function test_custom_role_can_be_updated_and_receive_permissions(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $customRole = Role::create([
            'name' => 'layout_editor',
            'display_name' => 'Layout Editor',
            'is_system' => false,
        ]);
        Permission::firstOrCreate(
            ['name' => 'articles.manage-assets'],
            ['module' => 'articles', 'description' => 'Manage article assets']
        );

        $this->putJson("/api/admin/rbac/roles/{$customRole->id}", [
            'name' => 'production_layout',
            'display_name' => 'Production Layout',
            'description' => 'Prepares production layouts',
        ])->assertStatus(200)
            ->assertJsonPath('name', 'production-layout')
            ->assertJsonPath('description', 'Prepares production layouts');

        $this->postJson("/api/admin/rbac/roles/{$customRole->id}/permissions", [
            'permissions' => ['articles.manage-assets'],
        ])->assertStatus(200)
            ->assertJsonFragment(['name' => 'articles.manage-assets']);
    }

    public function test_super_admin_can_retrieve_safe_roles_and_permission_matrix_payload(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $permission = Permission::firstOrCreate(
            ['name' => 'articles.create'],
            ['module' => 'articles', 'description' => 'Create articles']
        );
        $this->authorRole->permissions()->sync([$permission->id]);

        $rolesResponse = $this->getJson('/api/admin/rbac/roles')->assertOk();
        $roleRow = collect($rolesResponse->json())->firstWhere('name', 'author');

        $this->assertNotNull($roleRow);
        foreach (['id', 'name', 'display_name', 'description', 'is_system', 'is_locked', 'permissions'] as $key) {
            $this->assertArrayHasKey($key, $roleRow);
        }

        foreach (['users', 'password', 'tokens', 'pivot', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayNotHasKey($key, $roleRow);
        }

        $permissionRow = $roleRow['permissions'][0];
        foreach (['id', 'name', 'display_name', 'module', 'description'] as $key) {
            $this->assertArrayHasKey($key, $permissionRow);
        }
        foreach (['pivot', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayNotHasKey($key, $permissionRow);
        }

        $permissionsResponse = $this->getJson('/api/admin/rbac/permissions')->assertOk();
        $permissionsResponse->assertJsonFragment([
            'name' => 'articles.create',
            'display_name' => 'Articles Create',
            'module' => 'articles',
            'description' => 'Create articles',
        ]);
    }

    public function test_non_super_admin_roles_cannot_retrieve_roles_or_permissions(): void
    {
        $roles = array_merge(['author' => $this->authorRole], $this->systemRoles);

        foreach ($roles as $role) {
            $user = User::factory()->create(['role_id' => $role->id]);
            Sanctum::actingAs($user);

            $this->getJson('/api/admin/rbac/roles')->assertForbidden();
            $this->getJson('/api/admin/rbac/permissions')->assertForbidden();
        }
    }

    public function test_impersonated_session_cannot_retrieve_roles_or_permissions(): void
    {
        $target = User::factory()->create(['role_id' => $this->authorRole->id]);
        $tokenResult = $target->createToken('impersonation_token');

        ImpersonationSession::create([
            'original_super_admin_id' => $this->superAdmin->id,
            'impersonated_user_id' => $target->id,
            'impersonation_token_id' => $tokenResult->accessToken->id,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'stopped_at' => null,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $tokenResult->plainTextToken)
            ->getJson('/api/admin/rbac/roles')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer ' . $tokenResult->plainTextToken)
            ->getJson('/api/admin/rbac/permissions')
            ->assertForbidden();
    }

    public function test_invalid_and_duplicate_permission_assignments_are_rejected_safely(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $customRole = Role::create([
            'name' => 'layout_editor',
            'display_name' => 'Layout Editor',
            'is_system' => false,
        ]);
        $permission = Permission::firstOrCreate(
            ['name' => 'articles.manage-assets'],
            ['module' => 'articles', 'description' => 'Manage article assets']
        );

        $this->postJson("/api/admin/rbac/roles/{$customRole->id}/permissions", [
            'permissions' => [$permission->id, $permission->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('permissions');

        $this->postJson("/api/admin/rbac/roles/{$customRole->id}/permissions", [
            'permissions' => [999999],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
    }

    public function test_custom_roles_cannot_receive_protected_super_admin_permissions(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $customRole = Role::create([
            'name' => 'layout_editor',
            'display_name' => 'Layout Editor',
            'is_system' => false,
        ]);
        Permission::firstOrCreate(
            ['name' => 'roles.manage'],
            ['module' => 'roles', 'description' => 'Manage system roles']
        );
        $this->postJson("/api/admin/rbac/roles/{$customRole->id}/permissions", [
            'permissions' => ['roles.manage'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
    }

    public function test_custom_role_delete_permissions_are_filtered_from_sync(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $customRole = Role::create([
            'name' => 'layout_editor',
            'display_name' => 'Layout Editor',
            'is_system' => false,
        ]);
        $deletePermission = Permission::firstOrCreate(
            ['name' => 'articles.delete-any'],
            ['module' => 'articles', 'description' => 'Delete any article']
        );

        $this->postJson("/api/admin/rbac/roles/{$customRole->id}/permissions", [
            'permissions' => [$deletePermission->id],
        ])->assertOk()
            ->assertJsonMissing(['name' => 'articles.delete-any']);

        $this->assertFalse($customRole->fresh()->permissions()->where('name', 'articles.delete-any')->exists());
    }

    public function test_custom_role_delete_requires_no_assigned_users(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $customRole = Role::create([
            'name' => 'layout_editor',
            'display_name' => 'Layout Editor',
            'is_system' => false,
        ]);
        User::factory()->create(['role_id' => $customRole->id]);

        $this->deleteJson("/api/admin/rbac/roles/{$customRole->id}")
            ->assertStatus(400)
            ->assertJsonPath('message', 'This role is assigned to active users. Reassign users before deleting.');
    }

    public function test_create_and_edit_user_role_options_remain_minimal(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->getJson('/api/admin/rbac/roles')->assertOk();
        $roleRow = collect($response->json())->firstWhere('name', 'author');

        $this->assertNotNull($roleRow);
        $this->assertArrayHasKey('permissions', $roleRow);
        $this->assertArrayNotHasKey('users', $roleRow);
        $this->assertArrayNotHasKey('password', $roleRow);
        $this->assertArrayNotHasKey('tokens', $roleRow);
    }
}
