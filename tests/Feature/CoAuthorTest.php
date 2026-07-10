<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\Role;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoAuthorTest extends TestCase
{
    use RefreshDatabase;

    protected User $primaryAuthor;
    protected Magazine $magazine;
    protected Role $authorRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup roles and permissions
        $this->authorRole = Role::create([
            'name' => 'author',
            'display_name' => 'Author',
            'is_system' => true
        ]);

        // Seed permissions needed for author
        $createPermission = Permission::create([
            'name' => 'articles.create',
            'module' => 'articles',
            'description' => 'Create articles'
        ]);
        $editOwnPermission = Permission::create([
            'name' => 'articles.edit-own',
            'module' => 'articles',
            'description' => 'Edit own articles'
        ]);
        $viewOwnPermission = Permission::create([
            'name' => 'articles.view-own',
            'module' => 'articles',
            'description' => 'View own articles'
        ]);

        $this->authorRole->permissions()->sync([$createPermission->id, $editOwnPermission->id, $viewOwnPermission->id]);

        $this->primaryAuthor = User::create([
            'name' => 'Dr. Alice',
            'email' => 'alice@test.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);

        $this->magazine = Magazine::create([
            'title' => 'Test Science Magazine',
            'slug' => 'test-magazine',
            'description' => 'A test magazine',
        ]);
    }

    /**
     * Test submitting an article with new and existing co-authors.
     */
    public function test_submitting_article_with_co_authors_provisions_correctly(): void
    {
        $existingUser = User::create([
            'name' => 'Bob existing',
            'email' => 'bob@existing.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($this->primaryAuthor);

        $payload = [
            'magazine_id' => $this->magazine->id,
            'title' => 'Quantum Computing Breakthroughs',
            'abstract' => 'This is the abstract text.',
            'full_text' => 'This is the full text of the quantum paper.',
            'co_authors' => json_encode([
                [
                    'name' => 'Charlie New',
                    'email' => 'charlie@new.com',
                    'can_edit' => true,
                    'create_account' => true,
                ],
                [
                    'name' => 'Bob existing',
                    'email' => 'bob@existing.com',
                    'can_edit' => false,
                    'create_account' => true, // should link instead of create
                ],
                [
                    'name' => 'David Meta Only',
                    'email' => 'david@meta.com',
                    'can_edit' => false,
                    'create_account' => false,
                ]
            ])
        ];

        $response = $this->postJson('/api/articles', $payload);

        $response->assertStatus(211);
        
        // Assert Charlie New user was created
        $this->assertDatabaseHas('users', [
            'email' => 'charlie@new.com',
            'needs_password_reset' => true,
        ]);

        $charlie = User::where('email', 'charlie@new.com')->first();
        $this->assertNotNull($charlie);

        // Assert Charlie has pivot
        $this->assertDatabaseHas('article_author', [
            'co_author_email' => 'charlie@new.com',
            'user_id' => $charlie->id,
            'can_edit' => true,
            'account_provisioned' => true,
        ]);

        // Assert Bob existing linked and not duplicated
        $this->assertDatabaseHas('article_author', [
            'co_author_email' => 'bob@existing.com',
            'user_id' => $existingUser->id,
            'can_edit' => false,
            'account_provisioned' => false,
        ]);

        // Assert David linked with null user_id
        $this->assertDatabaseHas('article_author', [
            'co_author_email' => 'david@meta.com',
            'user_id' => null,
            'account_provisioned' => false,
        ]);
    }

    /**
     * Test co-author access policies.
     */
    public function test_co_author_view_and_edit_policies(): void
    {
        // Charlie is co-author with edit rights
        $charlie = User::create([
            'name' => 'Charlie Editor',
            'email' => 'charlie@test.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);

        // Dave is co-author with read-only rights
        $dave = User::create([
            'name' => 'Dave Viewer',
            'email' => 'dave@test.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);

        // Malloy is an unrelated author user
        $malloy = User::create([
            'name' => 'Malloy Stranger',
            'email' => 'malloy@test.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);

        // Create a pending article
        $article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->primaryAuthor->id,
            'title' => 'Pending Physics Discoveries',
            'slug' => 'pending-physics-discoveries',
            'abstract' => 'Abstract synopsis.',
            'full_text' => 'Full article body text.',
            'status' => 'pending',
        ]);

        // Link co-authors
        \DB::table('article_author')->insert([
            [
                'article_id' => $article->id,
                'user_id' => $charlie->id,
                'co_author_name' => 'Charlie Editor',
                'co_author_email' => 'charlie@test.com',
                'can_edit' => true,
                'account_provisioned' => false,
            ],
            [
                'article_id' => $article->id,
                'user_id' => $dave->id,
                'co_author_name' => 'Dave Viewer',
                'co_author_email' => 'dave@test.com',
                'can_edit' => false,
                'account_provisioned' => false,
            ]
        ]);

        // 1. Unauthenticated viewer gets 404 (hidden entirely)
        $response = $this->getJson('/api/articles/' . $article->slug);
        $response->assertStatus(404);

        // 2. Unrelated user gets 404 because unpublished article details are hidden on the public endpoint
        Sanctum::actingAs($malloy);
        $response = $this->getJson('/api/articles/' . $article->slug);
        $response->assertStatus(404);

        // 3. Co-author with read-only cannot view pending via the public article detail endpoint
        Sanctum::actingAs($dave);
        $response = $this->getJson('/api/articles/' . $article->slug);
        $response->assertStatus(404);

        // 4. Co-author with read-only cannot edit (update)
        $response = $this->putJson('/api/admin/articles/' . $article->id, [
            'magazine_id' => $this->magazine->id,
            'title' => 'Updated by Dave',
            'abstract' => 'Abstract synopsis.',
            'full_text' => 'Full article body text.',
        ]);
        $response->assertStatus(403);

        // 5. Co-author with edit rights can edit (update)
        $article->update(['status' => 'minor_review_rejected']);
        Sanctum::actingAs($charlie);
        $response = $this->putJson('/api/admin/articles/' . $article->id, [
            'magazine_id' => $this->magazine->id,
            'title' => 'Updated by Charlie Editor',
            'abstract' => 'Abstract synopsis.',
            'full_text' => 'Full article body text.',
        ]);
        $response->assertStatus(200);
    }

    /**
     * Test enforced password reset route.
     */
    public function test_enforced_password_reset_endpoint(): void
    {
        $coAuthor = User::create([
            'name' => 'Charlie New',
            'email' => 'charlie@new.com',
            'password' => Hash::make('temporaryPassword'),
            'role_id' => $this->authorRole->id,
            'needs_password_reset' => true,
        ]);

        Sanctum::actingAs($coAuthor);

        // Call reset enforced password
        $response = $this->postJson('/api/password/reset-enforced', [
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.needs_password_reset', false);

        // Verify database updated
        $this->assertDatabaseHas('users', [
            'email' => 'charlie@new.com',
            'needs_password_reset' => false,
        ]);
    }
}
