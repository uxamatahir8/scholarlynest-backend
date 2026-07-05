<?php

namespace Tests\Feature;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\MagazineIssue;
use App\Models\Article;
use App\Models\Permission;
use App\Models\User;
use App\Models\Role;
use App\Constants\ArticleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
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
        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $authorRole = Role::create(['name' => 'author', 'guard_name' => 'web']);

        foreach (['articles.create', 'articles.view-own', 'articles.edit-own', 'articles.approve'] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], [
                'module' => 'articles',
                'description' => $permissionName,
            ]);
        }

        $superAdminRole->permissions()->sync(Permission::pluck('id'));
        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.create', 'articles.view-own', 'articles.edit-own'])->pluck('id'));
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

    public function test_admin_can_create_magazine_with_uploaded_cover_image(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $response = $this->post('/api/admin/magazines', [
            'title' => 'Cover Upload Journal',
            'description' => 'A journal with a local cover.',
            'cover_image' => UploadedFile::fake()->image('cover.png', 320, 240),
        ]);

        $response->assertStatus(211)
            ->assertJsonPath('magazine.title', 'Cover Upload Journal')
            ->assertJsonPath('magazine.cover_image_url', fn ($url) => is_string($url) && str_contains($url, '/storage/covers/'));

        $magazine = Magazine::where('title', 'Cover Upload Journal')->firstOrFail();
        $this->assertStringStartsWith('storage/covers/', $magazine->cover_image);
        Storage::disk('public')->assertExists(str_replace('storage/', '', $magazine->cover_image));
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
                      'status' => 'submitted'
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
                 ->assertJsonPath('article.status', 'accepted');

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

        // Create 12 published articles to verify the default limit of 10 and latest order
        for ($i = 1; $i <= 12; $i++) {
            $article = Article::create([
                'magazine_id' => $magazine->id,
                'user_id' => $user->id,
                'title' => "Article $i",
                'slug' => "article-$i",
                'abstract' => "Abstract $i",
                'full_text' => "Full Text $i",
                'status' => 'published'
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
                 ->assertJsonCount(10, 'data')
                 ->assertJsonPath('data.0.title', 'Article 12')
                 ->assertJsonPath('data.9.title', 'Article 3');
    }

    /**
     * Test fetching table of contents returns published articles for the magazine.
     */
    public function test_magazine_details_returns_grouped_articles(): void
    {
        $magazine = Magazine::create(['title' => 'Biology Today', 'slug' => 'biology-today']);
        $user = User::factory()->create();

        // Create approved articles with specific published_at timestamps
        $article1 = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Article One',
            'slug' => 'article-one',
            'abstract' => 'Abstract 1',
            'full_text' => 'Full text 1',
            'status' => 'published',
            'published_at' => \Carbon\Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $article2 = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Article Two',
            'slug' => 'article-two',
            'abstract' => 'Abstract 2',
            'full_text' => 'Full text 2',
            'status' => 'published',
            'published_at' => \Carbon\Carbon::parse('2026-10-20 12:00:00'),
        ]);

        // Pending article should be filtered out
        Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Article Pending',
            'slug' => 'article-pending',
            'abstract' => 'Abstract P',
            'full_text' => 'Full text P',
            'status' => ArticleStatus::DRAFT,
            'published_at' => \Carbon\Carbon::parse('2026-10-22 12:00:00'),
        ]);

        $response = $this->getJson("/api/magazines/biology-today/table-of-contents");

        $response->assertStatus(200)
                 ->assertJsonPath('table_of_contents.2026.year', 2026)
                 ->assertJsonCount(1, 'table_of_contents.2026.months.10.articles')
                 ->assertJsonCount(1, 'table_of_contents.2026.months.09.articles')
                 ->assertJsonPath('table_of_contents.2026.months.10.articles.0.slug', 'article-two')
                 ->assertJsonPath('table_of_contents.2026.months.09.articles.0.slug', 'article-one')
                 ->assertJsonMissing(['slug' => 'article-pending']);
    }

    /**
     * Test fetching article details returns correct previous/next article slugs.
     */
    public function test_article_details_returns_adjacent_articles(): void
    {
        $magazine = Magazine::create(['title' => 'Physics Today', 'slug' => 'physics-today']);
        $user = User::factory()->create();

        // Create three approved articles chronologically ordered
        $art1 = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'First Article',
            'slug' => 'first-article',
            'abstract' => 'Abstract 1',
            'full_text' => 'Full text 1',
            'status' => 'published',
            'published_at' => \Carbon\Carbon::parse('2026-01-01 10:00:00'),
        ]);

        $art2 = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Second Article',
            'slug' => 'second-article',
            'abstract' => 'Abstract 2',
            'full_text' => 'Full text 2',
            'status' => 'published',
            'published_at' => \Carbon\Carbon::parse('2026-02-01 10:00:00'),
        ]);

        $art3 = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Third Article',
            'slug' => 'third-article',
            'abstract' => 'Abstract 3',
            'full_text' => 'Full text 3',
            'status' => 'published',
            'published_at' => \Carbon\Carbon::parse('2026-03-01 10:00:00'),
        ]);

        // Request second article - should have first as previous and third as next
        $response = $this->getJson("/api/articles/second-article");

        $response->assertStatus(200)
                 ->assertJsonPath('article.previous_article_slug', 'first-article')
                 ->assertJsonPath('article.next_article_slug', 'third-article')
                 ->assertJsonPath('article.previous_article_title', 'First Article')
                 ->assertJsonPath('article.next_article_title', 'Third Article')
                 ->assertJsonPath('previous_article_slug', 'first-article')
                 ->assertJsonPath('next_article_slug', 'third-article')
                 ->assertJsonPath('previous_article_title', 'First Article')
                 ->assertJsonPath('next_article_title', 'Third Article');

        // Request first article - should have null as previous and second as next
        $responseFirst = $this->getJson("/api/articles/first-article");
        $responseFirst->assertStatus(200)
                      ->assertJsonPath('article.previous_article_slug', null)
                      ->assertJsonPath('article.next_article_slug', 'second-article')
                      ->assertJsonPath('article.previous_article_title', null)
                      ->assertJsonPath('article.next_article_title', 'Second Article');

        // Request third article - should have second as previous and null as next
        $responseThird = $this->getJson("/api/articles/third-article");
        $responseThird->assertStatus(200)
                      ->assertJsonPath('article.previous_article_slug', 'second-article')
                      ->assertJsonPath('article.next_article_slug', null)
                      ->assertJsonPath('article.previous_article_title', 'Second Article')
                      ->assertJsonPath('article.next_article_title', null);
    }

    /**
     * Test user with auto-approve permission can approve article.
     */
    public function test_user_with_auto_approve_permission_can_approve_article(): void
    {
        Storage::fake('public');

        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $author = User::factory()->create();
        
        // Create custom role and assign permission
        $customRole = Role::create(['name' => 'pdf_compiler', 'guard_name' => 'web']);
        $permission = \App\Models\Permission::firstOrCreate([
            'name' => 'articles.auto-approve'
        ], [
            'module' => 'articles',
            'description' => 'Auto-Approve & Compile PDF'
        ]);
        $customRole->permissions()->attach($permission->id);

        $user = User::factory()->create(['role_id' => $customRole->id]);

        $article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $author->id,
            'title' => 'Auto Approved Article',
            'slug' => 'auto-approved-article',
            'abstract' => 'Abstract info',
            'full_text' => 'Full text info',
            'status' => 'pending'
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/review", [
            'status' => 'approved',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('article.status', 'accepted');

        $article->refresh();
        $this->assertNotNull($article->pdf_path);
    }

    /**
     * Test user without auto-approve permission is forbidden.
     */
    public function test_user_without_auto_approve_permission_cannot_approve_article(): void
    {
        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $author = User::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('author');

        $article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $author->id,
            'title' => 'Auto Approved Article 2',
            'slug' => 'auto-approved-article-2',
            'abstract' => 'Abstract info',
            'full_text' => 'Full text info',
            'status' => 'pending'
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/admin/articles/{$article->id}/review", [
            'status' => 'approved',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test submitting an article with featured image.
     */
    public function test_authenticated_author_can_submit_article_with_featured_image(): void
    {
        Storage::fake('public');

        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();
        $user->assignRole('author');

        Sanctum::actingAs($user);

        $imageFile = \Illuminate\Http\UploadedFile::fake()->image('featured.png');

        $response = $this->postJson('/api/articles', [
            'magazine_id' => $magazine->id,
            'title' => 'Quantum Logic Theory with Image',
            'abstract' => 'Abstract synopsis details',
            'full_text' => 'Full text content details',
            'featured_image' => $imageFile,
        ]);

        $response->assertStatus(211);
        
        $article = Article::where('title', 'Quantum Logic Theory with Image')->first();
        $this->assertNotNull($article);
        $this->assertNotNull($article->featured_image);
        $this->assertStringContainsString('storage/articles/', $article->featured_image);

        // Check file exists in fake storage
        $storedFilename = str_replace('storage/articles/', '', $article->featured_image);
        Storage::disk('public')->assertExists('articles/' . $storedFilename);
    }

    /**
     * Test updating an article's featured image.
     */
    public function test_author_can_update_article_featured_image(): void
    {
        Storage::fake('public');

        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();
        $user->assignRole('super_admin'); // use admin to allow updates easily without full policy verification

        Sanctum::actingAs($user);

        $article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Original Title',
            'slug' => 'original-title',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::DRAFT,
            'featured_image' => 'storage/articles/old_image.png'
        ]);

        // Place a fake file
        Storage::disk('public')->put('articles/old_image.png', 'fake old image content');

        $newImageFile = \Illuminate\Http\UploadedFile::fake()->image('new_featured.png');

        $response = $this->postJson("/api/admin/articles/{$article->id}", [
            '_method' => 'PUT',
            'magazine_id' => $magazine->id,
            'title' => 'Original Title',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'featured_image' => $newImageFile,
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertNotNull($article->featured_image);
        $this->assertNotEquals('storage/articles/old_image.png', $article->featured_image);

        // Old file deleted, new file exists
        Storage::disk('public')->assertMissing('articles/old_image.png');
        $newStoredFilename = str_replace('storage/articles/', '', $article->featured_image);
        Storage::disk('public')->assertExists('articles/' . $newStoredFilename);
    }

    /**
     * Test deleting an article's featured image.
     */
    public function test_author_can_delete_article_featured_image(): void
    {
        Storage::fake('public');

        $magazine = Magazine::create(['title' => 'A', 'slug' => 'a']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        Sanctum::actingAs($user);

        $article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Original Title',
            'slug' => 'original-title',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::DRAFT,
            'featured_image' => 'storage/articles/old_image.png'
        ]);

        Storage::disk('public')->put('articles/old_image.png', 'fake old image content');

        $response = $this->postJson("/api/admin/articles/{$article->id}", [
            '_method' => 'PUT',
            'magazine_id' => $magazine->id,
            'title' => 'Original Title',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'delete_featured_image' => 'true',
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertNull($article->featured_image);
        Storage::disk('public')->assertMissing('articles/old_image.png');
    }
    public function test_public_about_overview_resolves_only_requested_magazine(): void
    {
        Magazine::create(['title' => 'Magazine A', 'slug' => 'magazine-a', 'description' => 'A desc', 'about_text' => 'A about']);
        Magazine::create(['title' => 'Magazine B', 'slug' => 'magazine-b', 'description' => 'B desc', 'about_text' => 'B about']);

        $response = $this->getJson('/api/magazines/magazine-a/about-and-overview');

        $response->assertStatus(200)
            ->assertJsonPath('magazine.slug', 'magazine-a')
            ->assertJsonPath('magazine.about_text', 'A about')
            ->assertJsonMissing(['slug' => 'magazine-b']);
    }

    public function test_magazine_latest_published_articles_are_scoped_sorted_limited_and_public_safe(): void
    {
        $magazine = Magazine::create(['title' => 'Energy', 'slug' => 'energy']);
        $otherMagazine = Magazine::create(['title' => 'Robotics', 'slug' => 'robotics']);
        $user = User::factory()->create();

        for ($i = 1; $i <= 12; $i++) {
            Article::create([
                'magazine_id' => $magazine->id,
                'user_id' => $user->id,
                'title' => "Energy Article $i",
                'slug' => "energy-article-$i",
                'abstract' => "Abstract $i",
                'full_text' => "Private full text $i",
                'status' => 'published',
                'published_at' => now()->addMinutes($i),
            ]);
        }

        Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Draft Energy Article',
            'slug' => 'draft-energy-article',
            'abstract' => 'Draft',
            'full_text' => 'Draft private full text',
            'status' => 'submitted',
            'published_at' => now()->addDay(),
        ]);

        Article::create([
            'magazine_id' => $otherMagazine->id,
            'user_id' => $user->id,
            'title' => 'Other Magazine Article',
            'slug' => 'other-magazine-article',
            'abstract' => 'Other',
            'full_text' => 'Other private full text',
            'status' => 'published',
            'published_at' => now()->addDays(2),
        ]);

        $response = $this->getJson('/api/magazines/energy/latest-published-articles');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.title', 'Energy Article 12')
            ->assertJsonPath('data.9.title', 'Energy Article 3')
            ->assertJsonMissing(['title' => 'Draft Energy Article'])
            ->assertJsonMissing(['title' => 'Other Magazine Article'])
            ->assertJsonMissing(['status' => 'published'])
            ->assertJsonMissing(['full_text' => 'Private full text 12']);
    }

    public function test_public_table_of_contents_excludes_unpublished_and_groups_by_issue(): void
    {
        $magazine = Magazine::create(['title' => 'Biology', 'slug' => 'biology']);
        $otherMagazine = Magazine::create(['title' => 'Chemistry', 'slug' => 'chemistry']);
        $user = User::factory()->create();
        $issue = MagazineIssue::create([
            'magazine_id' => $magazine->id,
            'volume_number' => 2,
            'issue_number' => 1,
            'issue_year' => 2026,
            'is_published' => true,
            'published_at' => now(),
        ]);

        Article::create([
            'magazine_id' => $magazine->id,
            'magazine_issue_id' => $issue->id,
            'user_id' => $user->id,
            'title' => 'Published Biology',
            'slug' => 'published-biology',
            'abstract' => 'Abstract',
            'full_text' => 'Private full text',
            'status' => 'published',
            'doi' => '10.1234/bio.1',
            'page_start' => 11,
            'page_end' => 19,
            'published_at' => now(),
        ]);

        Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $user->id,
            'title' => 'Unpublished Biology',
            'slug' => 'unpublished-biology',
            'abstract' => 'Abstract',
            'full_text' => 'Private full text',
            'status' => 'submitted',
        ]);

        Article::create([
            'magazine_id' => $otherMagazine->id,
            'user_id' => $user->id,
            'title' => 'Published Chemistry',
            'slug' => 'published-chemistry',
            'abstract' => 'Abstract',
            'full_text' => 'Private full text',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/magazines/biology/table-of-contents');

        $response->assertStatus(200)
            ->assertJsonPath('magazine.slug', 'biology')
            ->assertJsonPath('table_of_contents.' . now()->year . '.months.' . now()->format('m') . '.articles.0.issue.volume_number', 2)
            ->assertJsonPath('table_of_contents.' . now()->year . '.months.' . now()->format('m') . '.articles.0.title', 'Published Biology')
            ->assertJsonPath('table_of_contents.' . now()->year . '.months.' . now()->format('m') . '.articles.0.page_start', 11)
            ->assertJsonMissing(['title' => 'Unpublished Biology'])
            ->assertJsonMissing(['title' => 'Published Chemistry'])
            ->assertJsonMissing(['full_text' => 'Private full text']);
    }

    public function test_custom_page_slugs_are_unique_per_magazine_and_can_repeat_across_magazines(): void
    {
        $magazineA = Magazine::create(['title' => 'Magazine A', 'slug' => 'magazine-a']);
        $magazineB = Magazine::create(['title' => 'Magazine B', 'slug' => 'magazine-b']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $first = $this->postJson("/api/admin/magazines/{$magazineA->id}/pages", [
            'title' => 'Editorial Board',
            'content' => 'A board',
        ]);
        $second = $this->postJson("/api/admin/magazines/{$magazineA->id}/pages", [
            'title' => 'Editorial Board',
            'content' => 'A board duplicate',
        ]);
        $third = $this->postJson("/api/admin/magazines/{$magazineB->id}/pages", [
            'title' => 'Editorial Board',
            'content' => 'B board',
        ]);

        $first->assertStatus(211)->assertJsonPath('page.slug', 'editorial-board');
        $second->assertStatus(211)->assertJsonPath('page.slug', 'editorial-board-2');
        $third->assertStatus(211)->assertJsonPath('page.slug', 'editorial-board');
    }

    public function test_public_custom_pages_are_magazine_scoped_and_active_only(): void
    {
        $magazineA = Magazine::create(['title' => 'Magazine A', 'slug' => 'magazine-a']);
        $magazineB = Magazine::create(['title' => 'Magazine B', 'slug' => 'magazine-b']);

        MagazinePage::create(['magazine_id' => $magazineA->id, 'title' => 'Ethics Policy', 'slug' => 'ethics-policy', 'content' => 'A ethics', 'status' => 'active']);
        MagazinePage::create(['magazine_id' => $magazineB->id, 'title' => 'Ethics Policy', 'slug' => 'ethics-policy', 'content' => 'B ethics', 'status' => 'active']);
        MagazinePage::create(['magazine_id' => $magazineA->id, 'title' => 'Draft Policy', 'slug' => 'draft-policy', 'content' => 'Draft content', 'status' => 'draft']);

        $this->getJson('/api/magazines/magazine-a/pages/ethics-policy')
            ->assertStatus(200)
            ->assertJsonPath('page.content', 'A ethics')
            ->assertJsonMissing(['content' => 'B ethics']);

        $this->getJson('/api/magazines/magazine-b/pages/ethics-policy')
            ->assertStatus(200)
            ->assertJsonPath('page.content', 'B ethics');

        $this->getJson('/api/magazines/magazine-a/pages/draft-policy')->assertStatus(404);
        $this->getJson('/api/magazines/magazine-a/pages/missing-policy')->assertStatus(404);
    }

    public function test_reserved_custom_page_slugs_are_rejected(): void
    {
        $magazine = Magazine::create(['title' => 'Magazine A', 'slug' => 'magazine-a']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/magazines/{$magazine->id}/pages", [
            'title' => 'Table of Contents',
            'content' => 'Reserved route collision',
        ])->assertStatus(422);
    }

    public function test_empty_magazine_public_lists_return_empty_collections(): void
    {
        Magazine::create(['title' => 'Empty', 'slug' => 'empty']);

        $this->getJson('/api/magazines/empty/latest-published-articles')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/magazines/empty/table-of-contents')
            ->assertStatus(200)
            ->assertJsonPath('table_of_contents', []);
    }

}
