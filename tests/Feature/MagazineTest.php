<?php

namespace Tests\Feature;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\Article;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

class MagazineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset Spatie cached permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Seed roles
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'author', 'guard_name' => 'web']);
    }

    /**
     * Test fetching magazines catalog.
     */
    public function test_can_fetch_magazines_catalog(): void
    {
        $magazine = Magazine::create([
            'title' => 'Test Medical Magazine',
            'slug' => 'test-medical-magazine',
            'cover_image' => 'https://images.unsplash.com/photo-1507679799987-c73779587ccf',
            'description' => 'Test Description',
            'about_text' => 'About Bio Tech',
        ]);

        $response = $this->getJson('/api/magazines');

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'title' => 'Test Medical Magazine',
                     'slug' => 'test-medical-magazine'
                 ]);
    }

    /**
     * Test fetching magazine shell with sorted sub-pages.
     */
    public function test_can_fetch_magazine_shell_with_sorted_pages(): void
    {
        $magazine = Magazine::create([
            'title' => 'Astrophysics Review',
            'slug' => 'astrophysics-review',
        ]);

        // Create pages with different sort orders
        $page2 = MagazinePage::create([
            'magazine_id' => $magazine->id,
            'title' => 'Page Two',
            'slug' => 'page-two',
            'content' => 'Content 2',
            'sort_order' => 10,
        ]);

        $page1 = MagazinePage::create([
            'magazine_id' => $magazine->id,
            'title' => 'Page One',
            'slug' => 'page-one',
            'content' => 'Content 1',
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/magazines/astrophysics-review');

        $response->assertStatus(200)
                 ->assertJsonPath('pages.0.title', 'Page One')
                 ->assertJsonPath('pages.1.title', 'Page Two');
    }

    /**
     * Test submitting an article requires authentication.
     */
    public function test_submitting_article_requires_auth(): void
    {
        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);

        $response = $this->postJson('/api/articles', [
            'magazine_id' => $magazine->id,
            'title' => 'Quantum Logic',
            'abstract' => 'Abstract',
            'full_text' => 'Full Text',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test submitting an article with valid authenticated author.
     */
    public function test_authenticated_author_can_submit_article(): void
    {
        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();
        $user->assignRole('author');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/articles', [
            'magazine_id' => $magazine->id,
            'title' => 'Quantum Logic Theory',
            'abstract' => 'Abstract synopsis details',
            'full_text' => 'Full text content details',
        ]);

        $response->assertStatus(211)
                 ->assertJsonFragment([
                      'title' => 'Quantum Logic Theory',
                      'status' => 'pending'
                  ]);

        $this->assertDatabaseHas('articles', [
            'title' => 'Quantum Logic Theory',
            'user_id' => $user->id
        ]);
    }

    /**
     * Test admin article review process.
     */
    public function test_admin_can_approve_article_and_trigger_pdf(): void
    {
        Storage::fake('public');

        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $author = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $author->id,
            'title' => 'A New Algorithmic Approach',
            'slug' => 'a-new-algorithmic-approach',
            'abstract' => 'Abstract info',
            'full_text' => 'Full text info',
            'status' => 'pending'
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/review", [
            'status' => 'approved',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('article.status', 'approved');

        // Check if a PDF path was generated on the database record
        $article->refresh();
        $this->assertNotNull($article->pdf_path);
        $this->assertStringContainsString('storage/articles/scholarlynest_article_', $article->pdf_path);
    }

    /**
     * Test fetching latest magazines.
     */
    public function test_can_fetch_latest_magazines(): void
    {
        // Create 12 magazines to verify the limit of 10 and latest order
        for ($i = 1; $i <= 12; $i++) {
            $magazine = Magazine::create([
                'title' => "Magazine $i",
                'slug' => "magazine-$i",
                'cover_image' => "https://example.com/cover-$i.png",
                'description' => "Description $i",
            ]);
            $magazine->created_at = now()->addMinutes($i);
            $magazine->save();
        }

        $response = $this->getJson('/api/magazines/latest');

        $response->assertStatus(200)
                 ->assertJsonCount(10, 'data')
                 ->assertJsonPath('data.0.title', 'Magazine 12')
                 ->assertJsonPath('data.9.title', 'Magazine 3');
    }

    /**
     * Test fetching latest articles.
     */
    public function test_can_fetch_latest_articles(): void
    {
        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();

        // Create 8 approved articles to verify the limit of 6 and latest order
        for ($i = 1; $i <= 8; $i++) {
            $article = Article::create([
                'magazine_id' => $magazine->id,
                'user_id' => $user->id,
                'title' => "Article $i",
                'slug' => "article-$i",
                'abstract' => "Abstract $i",
                'full_text' => "Full Text $i",
                'status' => 'approved'
            ]);
            $article->created_at = now()->addMinutes($i);
            $article->save();
        }

        // Create a pending article which should not be returned
        Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Pending Article',
            'slug' => 'pending-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full Text',
            'status' => 'pending'
        ]);

        $response = $this->getJson('/api/articles/latest');

        $response->assertStatus(200)
                 ->assertJsonCount(6, 'data')
                 ->assertJsonPath('data.0.title', 'Article 8')
                 ->assertJsonPath('data.5.title', 'Article 3');
    }
}
