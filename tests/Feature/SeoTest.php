<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & permissions
        $this->seedPermissions();
    }

    private function seedPermissions()
    {
        // Add basic scopes
        Permission::firstOrCreate(['name' => 'articles.edit-any'], ['module' => 'articles', 'description' => 'Edit any article']);
        Permission::firstOrCreate(['name' => 'articles.edit-own'], ['module' => 'articles', 'description' => 'Edit own articles']);

        // Add SEO permissions
        Permission::firstOrCreate(['name' => 'seo.articles'], ['module' => 'seo', 'description' => 'Manage SEO fields for articles']);
        Permission::firstOrCreate(['name' => 'seo.magazines'], ['module' => 'seo', 'description' => 'Manage SEO fields for magazines']);
        Permission::firstOrCreate(['name' => 'seo.cms-pages'], ['module' => 'seo', 'description' => 'Manage SEO fields for CMS pages']);
    }

    /**
     * Test that SEO permissions exist.
     */
    public function test_seo_permissions_seeded(): void
    {
        $this->assertDatabaseHas('permissions', ['name' => 'seo.articles']);
        $this->assertDatabaseHas('permissions', ['name' => 'seo.magazines']);
        $this->assertDatabaseHas('permissions', ['name' => 'seo.cms-pages']);
    }

    /**
     * Test that a user with seo.articles and articles.edit-any can edit SEO on any article.
     */
    public function test_seo_only_update_all_articles(): void
    {
        $role = Role::create(['name' => 'seo_editor']);
        $role->permissions()->sync(
            Permission::whereIn('name', ['seo.articles', 'articles.edit-any'])->pluck('id')
        );

        $user = User::create([
            'name' => 'SEO Editor User',
            'email' => 'seoeditor@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $otherUser = User::create([
            'name' => 'Other Author',
            'email' => 'author@example.com',
            'password' => bcrypt('password'),
        ]);

        $magazine = Magazine::create([
            'title' => 'Scientific Review',
            'slug' => 'scientific-review',
        ]);

        $article = Article::create([
            'title' => 'Original Title',
            'slug' => 'original-slug',
            'user_id' => $otherUser->id,
            'magazine_id' => $magazine->id,
            'abstract' => 'Original Abstract',
            'full_text' => 'Original Content',
            'status' => 'approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/seo", [
            'seo_title' => 'New SEO Title',
            'seo_description' => 'New SEO Description',
            'seo_keywords' => 'keyword1, keyword2',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'seo_title' => 'New SEO Title',
            'seo_description' => 'New SEO Description',
            'seo_keywords' => 'keyword1, keyword2',
        ]);
    }

    /**
     * Test that a user with seo.articles and articles.edit-own can edit SEO on their own article.
     */
    public function test_seo_own_scope_can_update_own_article(): void
    {
        $role = Role::create(['name' => 'author_seo']);
        $role->permissions()->sync(
            Permission::whereIn('name', ['seo.articles', 'articles.edit-own'])->pluck('id')
        );

        $user = User::create([
            'name' => 'Author SEO User',
            'email' => 'authorseo@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $magazine = Magazine::create([
            'title' => 'Scientific Review',
            'slug' => 'scientific-review',
        ]);

        $article = Article::create([
            'title' => 'My Article',
            'slug' => 'my-article',
            'user_id' => $user->id,
            'magazine_id' => $magazine->id,
            'abstract' => 'My Abstract',
            'full_text' => 'My Content',
            'status' => 'approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/seo", [
            'seo_title' => 'My SEO Title',
            'seo_description' => 'My SEO Description',
            'seo_keywords' => 'keyword1, keyword2',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'seo_title' => 'My SEO Title',
            'seo_description' => 'My SEO Description',
        ]);
    }

    /**
     * Test that a user with seo.articles and articles.edit-own CANNOT edit SEO on another user's article.
     */
    public function test_seo_own_scope_cannot_update_others_article(): void
    {
        $role = Role::create(['name' => 'author_seo']);
        $role->permissions()->sync(
            Permission::whereIn('name', ['seo.articles', 'articles.edit-own'])->pluck('id')
        );

        $user = User::create([
            'name' => 'Author SEO User',
            'email' => 'authorseo@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $otherUser = User::create([
            'name' => 'Other Author',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
        ]);

        $magazine = Magazine::create([
            'title' => 'Scientific Review',
            'slug' => 'scientific-review',
        ]);

        $article = Article::create([
            'title' => 'Others Article',
            'slug' => 'others-article',
            'user_id' => $otherUser->id,
            'magazine_id' => $magazine->id,
            'abstract' => 'Others Abstract',
            'full_text' => 'Others Content',
            'status' => 'approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/seo", [
            'seo_title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('articles', [
            'id' => $article->id,
            'seo_title' => 'Hacked Title',
        ]);
    }

    /**
     * Test that a dedicated role (has seo.articles but NO article edit permissions) can update any article.
     */
    public function test_seo_dedicated_role_can_update_all(): void
    {
        $role = Role::create(['name' => 'pure_seo']);
        $role->permissions()->sync(
            Permission::whereIn('name', ['seo.articles'])->pluck('id')
        );

        $user = User::create([
            'name' => 'SEO Specialist',
            'email' => 'seospec@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $otherUser = User::create([
            'name' => 'Author',
            'email' => 'author@example.com',
            'password' => bcrypt('password'),
        ]);

        $magazine = Magazine::create([
            'title' => 'Scientific Review',
            'slug' => 'scientific-review',
        ]);

        $article = Article::create([
            'title' => 'Some Article',
            'slug' => 'some-article',
            'user_id' => $otherUser->id,
            'magazine_id' => $magazine->id,
            'abstract' => 'Some Abstract',
            'full_text' => 'Some Content',
            'status' => 'approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/seo", [
            'seo_title' => 'Specialist Title',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'seo_title' => 'Specialist Title',
        ]);
    }

    /**
     * Test that a user without seo.articles gets a 403 forbidden response.
     */
    public function test_no_seo_permission_gets_403(): void
    {
        $role = Role::create(['name' => 'plain_author']);
        $role->permissions()->sync(
            Permission::whereIn('name', ['articles.edit-own'])->pluck('id')
        );

        $user = User::create([
            'name' => 'Plain Author',
            'email' => 'plain@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $magazine = Magazine::create([
            'title' => 'Scientific Review',
            'slug' => 'scientific-review',
        ]);

        $article = Article::create([
            'title' => 'My Article',
            'slug' => 'my-article',
            'user_id' => $user->id,
            'magazine_id' => $magazine->id,
            'abstract' => 'My Abstract',
            'full_text' => 'My Content',
            'status' => 'approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/seo", [
            'seo_title' => 'Fail Title',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test that public article details contains generated SEO fallbacks.
     */
    public function test_article_response_contains_seo_and_og_fields(): void
    {
        $user = User::create([
            'name' => 'Author User',
            'email' => 'author@example.com',
            'password' => bcrypt('password'),
        ]);

        $magazine = Magazine::create([
            'title' => 'Nature Computations',
            'slug' => 'nature-computations',
            'cover_image' => 'covers/nature.png',
        ]);

        $article = Article::create([
            'title' => 'Deep Learning Breakthroughs',
            'slug' => 'deep-learning-breakthroughs',
            'user_id' => $user->id,
            'magazine_id' => $magazine->id,
            'abstract' => 'This is a long abstract describing our breakthroughs in deep learning networks.',
            'full_text' => 'Full text content here...',
            'status' => 'approved',
        ]);

        $response = $this->getJson("/api/articles/deep-learning-breakthroughs");

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'seo_title' => 'Deep Learning Breakthroughs | Nature Computations',
                     'seo_description' => 'This is a long abstract describing our breakthroughs in deep learning networks.',
                     'og_image' => 'covers/nature.png',
                 ]);
    }
}
