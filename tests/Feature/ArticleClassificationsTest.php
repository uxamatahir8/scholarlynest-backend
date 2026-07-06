<?php

namespace Tests\Feature;

use App\Models\ArticleCategory;
use App\Models\ArticleType;
use App\Models\Language;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubjectArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleClassificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        Permission::firstOrCreate(['name' => 'settings.manage'], ['module' => 'settings', 'description' => 'settings.manage']);
        $adminRole->permissions()->sync(Permission::where('name', 'settings.manage')->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
    }

    public function test_authenticated_users_can_list_classifications(): void
    {
        Sanctum::actingAs($this->author);

        $this->getJson('/api/article-types')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Research Article']);

        $this->getJson('/api/article-categories')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Original Research']);

        $this->getJson('/api/subject-areas')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Computational Science']);

        $this->getJson('/api/languages')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'English']);
    }

    public function test_unauthorized_users_cannot_create_classifications(): void
    {
        Sanctum::actingAs($this->author);

        $this->postJson('/api/admin/article-types', ['name' => 'New Type'])
            ->assertStatus(403);

        $this->postJson('/api/admin/article-categories', ['name' => 'New Category'])
            ->assertStatus(403);
    }

    public function test_admin_can_crud_article_types(): void
    {
        Sanctum::actingAs($this->admin);

        // Create
        $response = $this->postJson('/api/admin/article-types', [
            'name' => 'Perspectives',
            'description' => 'Perspectives and ideas.',
        ]);
        $response->assertStatus(201);
        $typeId = $response->json('data.id');

        $this->assertDatabaseHas('article_types', ['name' => 'Perspectives']);

        // Update
        $this->putJson("/api/admin/article-types/{$typeId}", [
            'name' => 'Perspectives Updated',
            'is_active' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('article_types', [
            'id' => $typeId,
            'name' => 'Perspectives Updated',
            'is_active' => false,
        ]);

        // Delete
        $this->deleteJson("/api/admin/article-types/{$typeId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('article_types', ['id' => $typeId]);
    }

    public function test_admin_can_crud_article_categories(): void
    {
        Sanctum::actingAs($this->admin);

        // Create
        $response = $this->postJson('/api/admin/article-categories', [
            'name' => 'Brief Reports',
            'description' => 'Short findings reports.',
        ]);
        $response->assertStatus(201);
        $catId = $response->json('data.id');

        $this->assertDatabaseHas('article_categories', ['name' => 'Brief Reports']);

        // Update
        $this->putJson("/api/admin/article-categories/{$catId}", [
            'name' => 'Brief Reports Updated',
            'is_active' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('article_categories', [
            'id' => $catId,
            'name' => 'Brief Reports Updated',
            'is_active' => false,
        ]);

        // Delete
        $this->deleteJson("/api/admin/article-categories/{$catId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('article_categories', ['id' => $catId]);
    }

    public function test_admin_can_crud_subject_areas(): void
    {
        Sanctum::actingAs($this->admin);

        // Create
        $response = $this->postJson('/api/admin/subject-areas', [
            'name' => 'Quantum Mechanics',
            'description' => 'Physics of quantum scale.',
        ]);
        $response->assertStatus(201);
        $areaId = $response->json('data.id');

        $this->assertDatabaseHas('subject_areas', ['name' => 'Quantum Mechanics']);

        // Update
        $this->putJson("/api/admin/subject-areas/{$areaId}", [
            'name' => 'Quantum Mechanics Updated',
            'is_active' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('subject_areas', [
            'id' => $areaId,
            'name' => 'Quantum Mechanics Updated',
            'is_active' => false,
        ]);

        // Delete
        $this->deleteJson("/api/admin/subject-areas/{$areaId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('subject_areas', ['id' => $areaId]);
    }

    public function test_admin_can_crud_languages(): void
    {
        Sanctum::actingAs($this->admin);

        // Create
        $response = $this->postJson('/api/admin/languages', [
            'name' => 'Italian',
            'code' => 'it',
        ]);
        $response->assertStatus(201);
        $langId = $response->json('data.id');

        $this->assertDatabaseHas('languages', ['name' => 'Italian', 'code' => 'it']);

        // Update
        $this->putJson("/api/admin/languages/{$langId}", [
            'name' => 'Italian Updated',
            'code' => 'it-updated',
            'is_active' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('languages', [
            'id' => $langId,
            'name' => 'Italian Updated',
            'code' => 'it-updated',
            'is_active' => false,
        ]);

        // Delete
        $this->deleteJson("/api/admin/languages/{$langId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('languages', ['id' => $langId]);
    }
}
