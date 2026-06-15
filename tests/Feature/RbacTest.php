<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected Role $superAdminRole;
    protected Role $authorRole;
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
}
