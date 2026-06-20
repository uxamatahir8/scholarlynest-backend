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

    /**
     * Super Admin can access GET /api/admin/users
     */
    public function test_super_admin_can_access_paginated_users_endpoint(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $this->getJson('/api/admin/users')->assertOk();
    }

    /**
     * Legacy Admin and other roles cannot access GET /api/admin/users
     */
    public function test_other_roles_cannot_access_paginated_users_endpoint(): void
    {
        $nonSuperAdminRoles = ['admin', 'author', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'];

        foreach ($nonSuperAdminRoles as $roleName) {
            Sanctum::actingAs($this->user($roleName));
            $this->getJson('/api/admin/users')->assertForbidden();
        }
    }

    /**
     * Search returns results by name
     */
    public function test_search_returns_results_by_name(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        User::factory()->create(['name' => 'John Doe UniqueName', 'role_id' => $this->roles['editor']->id]);
        User::factory()->create(['name' => 'Jane Smith OrdinaryName', 'role_id' => $this->roles['editor']->id]);

        $response = $this->getJson('/api/admin/users?search=UniqueName');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('John Doe UniqueName', $data[0]['name']);
    }

    /**
     * Search returns results by email
     */
    public function test_search_returns_results_by_email(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        User::factory()->create(['name' => 'John Doe', 'email' => 'john.doe.unique.email@example.com', 'role_id' => $this->roles['editor']->id]);
        User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane.smith@example.com', 'role_id' => $this->roles['editor']->id]);

        $response = $this->getJson('/api/admin/users?search=unique.email');
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('john.doe.unique.email@example.com', $data[0]['email']);
    }

    /**
     * Search returns results by role
     */
    public function test_search_returns_results_by_role(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        User::factory()->create(['name' => 'Special Editor', 'role_id' => $this->roles['editor']->id]);
        User::factory()->create(['name' => 'Special Author', 'role_id' => $this->roles['author']->id]);

        // Search by role name
        $response = $this->getJson('/api/admin/users?search=editor');
        $response->assertOk();
        $data = $response->json('data');
        // Note: the logged-in super_admin is excluded from query, so only editor should be returned
        $this->assertTrue(collect($data)->contains(fn($u) => $u['name'] === 'Special Editor'));
        $this->assertFalse(collect($data)->contains(fn($u) => $u['name'] === 'Special Author'));
    }

    /**
     * Search respects pagination
     */
    public function test_search_respects_pagination(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        // Create 25 editor users (so total = 25 editors + 1 logged-in super_admin, who is excluded, so 25 users in list)
        User::factory()->count(25)->create(['role_id' => $this->roles['editor']->id]);

        // Page 1 with per_page = 15
        $response = $this->getJson('/api/admin/users?page=1&per_page=15');
        $response->assertOk();
        $this->assertEquals(1, $response->json('current_page'));
        $this->assertEquals(2, $response->json('last_page'));
        $this->assertEquals(15, count($response->json('data')));
        $this->assertEquals(25, $response->json('total'));

        // Page 2 with per_page = 15
        $response = $this->getJson('/api/admin/users?page=2&per_page=15');
        $response->assertOk();
        $this->assertEquals(2, $response->json('current_page'));
        $this->assertEquals(10, count($response->json('data')));
    }

    /**
     * Search resets/behaves correctly with blank whitespace
     */
    public function test_search_resets_behaves_correctly_with_blank_whitespace(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        User::factory()->count(5)->create(['role_id' => $this->roles['editor']->id]);

        $response = $this->getJson('/api/admin/users?' . http_build_query(['search' => '   ']));
        $response->assertOk();
        // Returns all 5 editors
        $this->assertEquals(5, count($response->json('data')));
    }

    /**
     * Invalid pagination values are safely validated
     */
    public function test_invalid_pagination_values_are_safely_validated(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        // Invalid page
        $this->getJson('/api/admin/users?page=0')->assertStatus(422);
        $this->getJson('/api/admin/users?page=-1')->assertStatus(422);

        // Invalid per_page (too small or too large)
        $this->getJson('/api/admin/users?per_page=5')->assertStatus(422);
        $this->getJson('/api/admin/users?per_page=150')->assertStatus(422);
    }

    /**
     * User-list payload excludes sensitive fields
     */
    public function test_user_list_payload_excludes_sensitive_fields(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $targetUser = User::factory()->create([
            'role_id' => $this->roles['editor']->id,
            'google_id' => '123456789',
            'verification_code' => 'abcde',
            'password' => 'somehash',
            'two_factor_code' => '123456',
        ]);

        $response = $this->getJson('/api/admin/users');
        $response->assertOk();
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $userRow = collect($data)->firstWhere('id', $targetUser->id);
        $this->assertNotNull($userRow);

        // Assert existing expected keys
        $expectedKeys = ['id', 'name', 'email', 'profile_image', 'roles', 'status', 'created_at'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $userRow);
        }

        // Assert banned sensitive keys
        $sensitiveKeys = [
            'password',
            'password_hash',
            'remember_token',
            'access_token',
            'verification_code',
            'verification_code_expires_at',
            'password_change_code',
            'two_factor_code',
            'google_id',
            'permissions',
            'permissions_matrix',
            'university_name' // university_name is not in the explicit serializer list of Phase 2
        ];
        foreach ($sensitiveKeys as $key) {
            $this->assertArrayNotHasKey($key, $userRow);
        }
    }
}
