<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Magazine;
use App\Models\Article;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UniversityMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected Role $superAdminRole;
    protected Role $authorRole;
    protected User $superAdmin;
    protected User $author;
    protected Magazine $magazine;

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

        foreach (['articles.create', 'articles.view-own', 'articles.edit-own'] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], [
                'module' => 'articles',
                'description' => $permissionName,
            ]);
        }

        $this->superAdminRole->permissions()->sync(Permission::pluck('id'));
        $this->authorRole->permissions()->sync(Permission::whereIn('name', ['articles.create', 'articles.view-own', 'articles.edit-own'])->pluck('id'));

        $this->superAdmin = User::create([
            'name' => 'Admin Alice',
            'email' => 'alice@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role_id' => $this->superAdminRole->id,
            'email_verified_at' => now(),
            'university_name' => 'Stanford University',
        ]);

        $this->author = User::create([
            'name' => 'Author Bob',
            'email' => 'bob@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
            'university_name' => 'MIT',
        ]);

        $this->magazine = Magazine::create([
            'title' => 'Scientific Computing',
            'slug' => 'scientific-computing',
            'description' => 'A computer science magazine',
        ]);
    }

    /**
     * Test standard registration validates university_name.
     */
    public function test_registration_requires_university_name(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Dr. Charlie Brown',
            'email' => 'charlie@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['university_name']);
    }

    /**
     * Test standard registration saves university_name.
     */
    public function test_registration_saves_university_name(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Dr. Charlie Brown',
            'email' => 'charlie@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'university_name' => 'Harvard University',
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('users', [
            'email' => 'charlie@test.com',
            'university_name' => 'Harvard University',
        ]);
    }

    /**
     * Test profile update saves university_name.
     */
    public function test_profile_update_saves_university_name(): void
    {
        Sanctum::actingAs($this->author);

        $response = $this->putJson('/api/profile', [
            'university_name' => 'Yale University',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Yale University', $this->author->refresh()->university_name);
    }

    /**
     * Test support for PUT /api/user/profile route.
     */
    public function test_user_profile_route_updates_profile(): void
    {
        Sanctum::actingAs($this->author);

        $response = $this->putJson('/api/user/profile', [
            'university_name' => 'Oxford University',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Oxford University', $this->author->refresh()->university_name);
    }

    /**
     * Test admin user creation validates university_name.
     */
    public function test_admin_user_creation_requires_university_name(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/admin/rbac/users', [
            'name' => 'New User',
            'email' => 'newuser@test.com',
            'role_id' => $this->authorRole->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['university_name']);
    }

    /**
     * Test admin user creation saves university_name.
     */
    public function test_admin_user_creation_saves_university_name(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/admin/rbac/users', [
            'name' => 'New User',
            'email' => 'newuser@test.com',
            'role_id' => $this->authorRole->id,
            'university_name' => 'Caltech',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@test.com',
            'university_name' => 'Caltech',
        ]);
    }

    /**
     * Test co-author creation and syncing with university name.
     */
    public function test_co_author_creation_saves_university_name(): void
    {
        Sanctum::actingAs($this->author);

        $response = $this->postJson('/api/articles', [
            'magazine_id' => $this->magazine->id,
            'title' => 'Novel Quantum Computing Methods',
            'abstract' => 'Quantum computing methods are explored...',
            'full_text' => 'This is full text content.',
            'terms_accepted' => true,
            'co_authors' => json_encode([
                [
                    'name' => 'Dr. David',
                    'email' => 'david@test.com',
                    'university_name' => 'Princeton University',
                    'can_edit' => true,
                    'create_account' => true,
                ]
            ])
        ]);

        $response->assertStatus(211); // 211 is the success status for article store
        
        // Assert pivot table entry has university_name
        $this->assertDatabaseHas('article_author', [
            'co_author_email' => 'david@test.com',
            'university_name' => 'Princeton University',
        ]);

        // Assert newly provisioned user has university_name
        $this->assertDatabaseHas('users', [
            'email' => 'david@test.com',
            'university_name' => 'Princeton University',
        ]);
    }
}
