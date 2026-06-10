<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
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
}
