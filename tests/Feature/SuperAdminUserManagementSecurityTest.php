<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

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

    /**
     * Super Admin can create a standard user (e.g., editor).
     */
    public function test_super_admin_can_create_standard_user(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->postJson('/api/admin/users', [
            'name' => 'New Staff Editor',
            'email' => 'new.editor@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['editor']->id,
            'university_name' => 'Scholarly University',
            'status' => 'active'
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'User created successfully.');
        $this->assertDatabaseHas('users', [
            'email' => 'new.editor@example.com',
            'role_id' => $this->roles['editor']->id,
        ]);
    }

    /**
     * Legacy Admin and other roles cannot create a user through Super Admin endpoint.
     */
    public function test_other_roles_cannot_create_user(): void
    {
        $nonSuperAdminRoles = ['admin', 'author', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'];

        foreach ($nonSuperAdminRoles as $roleName) {
            Sanctum::actingAs($this->user($roleName));

            $response = $this->postJson('/api/admin/users', [
                'name' => 'Disallowed User',
                'email' => 'bad.create@example.com',
                'password' => 'SecurePass123!',
                'password_confirmation' => 'SecurePass123!',
                'role_id' => $this->roles['editor']->id,
            ]);

            $response->assertStatus(403);
        }
    }

    /**
     * Email uniqueness validation works.
     */
    public function test_create_user_email_uniqueness_validation(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Another User',
            'email' => 'duplicate@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['editor']->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    /**
     * Password confirmation validation works.
     */
    public function test_create_user_password_confirmation_validation(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Mismatched User',
            'email' => 'mismatch@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass123!',
            'role_id' => $this->roles['editor']->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    /**
     * Created-user response excludes sensitive fields.
     */
    public function test_created_user_response_excludes_sensitive_fields(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Safe User',
            'email' => 'safe.user@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['editor']->id,
        ]);

        $response->assertStatus(201);
        $userRow = $response->json('data');

        // Verify shape and banned keys
        $this->assertArrayHasKey('id', $userRow);
        $this->assertArrayHasKey('name', $userRow);
        $this->assertArrayHasKey('email', $userRow);
        $this->assertArrayHasKey('roles', $userRow);
        $this->assertArrayHasKey('status', $userRow);
        $this->assertArrayHasKey('created_at', $userRow);

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
            'university_name'
        ];
        foreach ($sensitiveKeys as $key) {
            $this->assertArrayNotHasKey($key, $userRow);
        }
    }

    /**
     * Super Admin can create a Sub Editor with one Editor or multiple Editors.
     */
    public function test_super_admin_can_create_sub_editor_with_editors(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $editor1 = User::factory()->create(['role_id' => $this->roles['editor']->id]);
        $editor2 = User::factory()->create(['role_id' => $this->roles['editor']->id]);

        // Create with one Editor
        $response = $this->postJson('/api/admin/users', [
            'name' => 'Sub Editor One',
            'email' => 'sub1@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['sub_editor']->id,
            'editor_ids' => [$editor1->id]
        ]);

        $response->assertStatus(201);
        $sub1 = User::where('email', 'sub1@example.com')->first();
        $this->assertNotNull($sub1);
        $this->assertCount(1, $sub1->assignedEditors);
        $this->assertEquals($editor1->id, $sub1->assignedEditors[0]->id);

        // Create with multiple Editors
        $response2 = $this->postJson('/api/admin/users', [
            'name' => 'Sub Editor Multi',
            'email' => 'sub2@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['sub_editor']->id,
            'editor_ids' => [$editor1->id, $editor2->id]
        ]);

        $response2->assertStatus(201);
        $sub2 = User::where('email', 'sub2@example.com')->first();
        $this->assertNotNull($sub2);
        $this->assertCount(2, $sub2->assignedEditors);
    }

    /**
     * Creating Sub Editor without or with empty editor_ids fails.
     */
    public function test_creating_sub_editor_without_editor_ids_fails(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        // Without editor_ids key
        $response = $this->postJson('/api/admin/users', [
            'name' => 'Sub Editor Bad',
            'email' => 'sub.bad@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['sub_editor']->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('editor_ids');
        $this->assertEquals('At least one Editor must be assigned to a Sub Editor.', $response->json('errors.editor_ids.0'));

        // With empty editor_ids
        $response2 = $this->postJson('/api/admin/users', [
            'name' => 'Sub Editor Bad 2',
            'email' => 'sub.bad2@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['sub_editor']->id,
            'editor_ids' => []
        ]);

        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors('editor_ids');
    }

    /**
     * Creating Sub Editor with a non-Editor ID fails.
     */
    public function test_creating_sub_editor_with_non_editor_id_fails(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $author = User::factory()->create(['role_id' => $this->roles['author']->id]);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Sub Editor Bad 3',
            'email' => 'sub.bad3@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['sub_editor']->id,
            'editor_ids' => [$author->id]
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('At least one Editor must be assigned to a Sub Editor.', $response->json('message'));
    }

    /**
     * Failed Sub Editor Editor-link sync does not leave an orphan account.
     */
    public function test_failed_sub_editor_link_does_not_leave_orphan(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $editor = User::factory()->create(['role_id' => $this->roles['editor']->id]);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Failed User',
            'email' => 'rollback-test@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['sub_editor']->id,
            'editor_ids' => [$editor->id]
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => 'rollback-test@example.com']);
    }

    /**
     * Duplicate Editor-Sub Editor pivot records are prevented.
     */
    public function test_duplicate_editor_sub_editor_pivots_prevented(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $editor = User::factory()->create(['role_id' => $this->roles['editor']->id]);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Sub Editor Unique Pivots',
            'email' => 'sub.pivots@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['sub_editor']->id,
            'editor_ids' => [$editor->id, $editor->id] // Pass duplicate
        ]);

        $response->assertStatus(201);
        $sub = User::where('email', 'sub.pivots@example.com')->first();
        $this->assertNotNull($sub);

        // Assert only ONE pivot row exists in db
        $pivotCount = DB::table('editor_sub_editor')
            ->where('sub_editor_id', $sub->id)
            ->where('editor_id', $editor->id)
            ->count();
        $this->assertEquals(1, $pivotCount);
    }

    /**
     * Creating a non-Sub Editor does not create Editor–Sub Editor pivot records.
     */
    public function test_creating_non_sub_editor_does_not_create_pivots(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $editor = User::factory()->create(['role_id' => $this->roles['editor']->id]);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Non Sub Editor',
            'email' => 'nonsub@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['editor']->id,
            'editor_ids' => [$editor->id] // Send editor_ids anyway
        ]);

        $response->assertStatus(201);
        $user = User::where('email', 'nonsub@example.com')->first();
        $this->assertNotNull($user);

        // Assert no pivot rows exist in db
        $pivotCount = DB::table('editor_sub_editor')
            ->where('sub_editor_id', $user->id)
            ->count();
        $this->assertEquals(0, $pivotCount);
    }
}
