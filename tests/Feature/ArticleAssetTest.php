<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\ArticleAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\UploadedFile;

class ArticleAssetTest extends TestCase
{
    use RefreshDatabase;

    protected User $author;
    protected User $unauthorizedUser;
    protected Article $article;
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
        $createPermission = Permission::firstOrCreate([
            'name' => 'articles.create',
        ], [
            'module' => 'articles',
            'description' => 'Create articles'
        ]);
        
        $editOwnPermission = Permission::firstOrCreate([
            'name' => 'articles.edit-own',
        ], [
            'module' => 'articles',
            'description' => 'Edit own articles'
        ]);

        $viewOwnPermission = Permission::firstOrCreate([
            'name' => 'articles.view-own',
        ], [
            'module' => 'articles',
            'description' => 'View own articles'
        ]);

        $manageAssetsPermission = Permission::firstOrCreate([
            'name' => 'articles.manage-assets',
        ], [
            'module' => 'articles',
            'description' => 'Manage supplementary assets'
        ]);

        $this->authorRole->permissions()->sync([
            $createPermission->id, 
            $editOwnPermission->id, 
            $viewOwnPermission->id,
            $manageAssetsPermission->id
        ]);

        // Setup users linking with role_id
        $this->author = User::create([
            'name' => 'Dr. Alice',
            'email' => 'alice@test.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);

        $this->unauthorizedUser = User::create([
            'name' => 'Dr. Bob',
            'email' => 'bob@test.com',
            'password' => Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);

        // Setup parent magazine and article
        $magazine = Magazine::create([
            'title' => 'Scientific Computing',
            'slug' => 'scientific-computing',
            'description' => 'A magazine on scientific computing.',
        ]);

        $this->article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $this->author->id,
            'title' => 'High Performance Parallel Systems',
            'slug' => 'parallel-systems',
            'abstract' => 'This is the abstract.',
            'full_text' => 'This is the full text.',
            'status' => 'minor_review_rejected',
        ]);

        // Fake public disk
        Storage::fake('public');
    }

    /**
     * Test author can upload assets to their article.
     */
    public function test_author_can_upload_article_assets(): void
    {
        Sanctum::actingAs($this->author);

        $file = UploadedFile::fake()->create('dataset.xlsx', 500, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson("/api/articles/{$this->article->id}/assets", [
            'file' => $file,
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'message',
                     'asset' => [
                         'id',
                         'article_id',
                         'file_path',
                         'original_filename',
                         'file_size',
                         'mime_type',
                     ]
                 ]);

        $this->assertDatabaseHas('article_assets', [
            'article_id' => $this->article->id,
            'original_filename' => 'dataset.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        $this->assertDatabaseHas('article_files', [
            'article_id' => $this->article->id,
            'file_type' => 'supplementary',
            'original_name' => 'dataset.xlsx',
        ]);

        $asset = ArticleAsset::first();
        $relativePath = str_replace('storage/', '', $asset->file_path);
        Storage::disk('public')->assertExists($relativePath);
    }

    /**
     * Test malware scan rejects infected files containing the EICAR test string.
     */
    public function test_malware_scan_rejects_eicar_infected_files(): void
    {
        Sanctum::actingAs($this->author);

        // Standard EICAR signature for virus scanner validation
        $eicarString = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $file = UploadedFile::fake()->createWithContent('malicious.txt', $eicarString);

        $response = $this->postJson("/api/articles/{$this->article->id}/assets", [
            'file' => $file,
        ]);

        $response->assertStatus(422)
                 ->assertJsonFragment([
                     'message' => 'Malware scan failed: Infected file detected.'
                 ]);

        $this->assertDatabaseEmpty('article_assets');
    }

    /**
     * Test unauthorized users cannot upload assets to other authors' articles.
     */
    public function test_unauthorized_user_cannot_upload_article_assets(): void
    {
        Sanctum::actingAs($this->unauthorizedUser);

        $file = UploadedFile::fake()->create('slides.pdf', 1000, 'application/pdf');

        $response = $this->postJson("/api/articles/{$this->article->id}/assets", [
            'file' => $file,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseEmpty('article_assets');
    }

    /**
     * Test only Super Admin can delete supplementary assets.
     */
    public function test_only_super_admin_can_delete_article_assets(): void
    {
        Sanctum::actingAs($this->author);

        // Store a fake file physically first
        $path = Storage::disk('public')->putFile('assets', UploadedFile::fake()->create('doc.docx'));

        $asset = ArticleAsset::create([
            'article_id' => $this->article->id,
            'file_path' => 'storage/' . $path,
            'original_filename' => 'doc.docx',
            'file_size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        Storage::disk('public')->assertExists($path);

        $this->deleteJson("/api/articles/assets/{$asset->id}")->assertForbidden();
        $this->assertDatabaseHas('article_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists($path);

        $superRole = Role::create([
            'name' => 'super_admin',
            'display_name' => 'Super Admin',
            'is_system' => true,
        ]);
        $superRole->permissions()->sync(Permission::pluck('id'));
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@test.com',
            'password' => Hash::make('password123'),
            'role_id' => $superRole->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/articles/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Asset deleted successfully.']);

        $this->assertDatabaseMissing('article_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * Test public user can download assets for approved articles.
     */
    public function test_public_user_can_download_assets_for_approved_articles(): void
    {
        // Approve the article
        $this->article->update(['status' => 'approved']);

        // Create a fake file in disk
        $path = Storage::disk('public')->putFile('assets', UploadedFile::fake()->create('supplement.pdf'));

        $asset = ArticleAsset::create([
            'article_id' => $this->article->id,
            'file_path' => 'storage/' . $path,
            'original_filename' => 'supplement.pdf',
            'file_size' => 4567,
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->get("/api/articles/assets/{$asset->id}/download");

        $response->assertStatus(200)
                 ->assertHeader('Content-Type', 'application/pdf')
                 ->assertHeader('Content-Disposition', 'attachment; filename="supplement.pdf"')
                 ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Test public user cannot download assets for unapproved articles.
     */
    public function test_public_user_cannot_download_assets_for_unapproved_articles(): void
    {
        // Keep article status as pending
        $this->article->update(['status' => 'pending']);

        $path = Storage::disk('public')->putFile('assets', UploadedFile::fake()->create('secret.xlsx'));

        $asset = ArticleAsset::create([
            'article_id' => $this->article->id,
            'file_path' => 'storage/' . $path,
            'original_filename' => 'secret.xlsx',
            'file_size' => 999,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        // Access without authentication
        $response = $this->getJson("/api/articles/assets/{$asset->id}/download");
        $response->assertStatus(403);
    }
}
