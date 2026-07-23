<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleAsset;
use App\Models\Magazine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectStorageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_published_private_asset_redirects_directly_without_remote_exists_or_streaming(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $publication = Magazine::create(['title' => 'Storage', 'slug' => 'storage', 'publication_type' => 'magazine']);
        $article = Article::create(['magazine_id' => $publication->id, 'user_id' => $user->id, 'title' => 'Direct', 'slug' => 'direct', 'abstract' => 'A', 'full_text' => 'T', 'status' => 'published']);
        $asset = ArticleAsset::create(['article_id' => $article->id, 'asset_type' => 'supplementary', 'original_filename' => 'report.pdf', 'safe_original_filename' => 'report.pdf', 'file_path' => 'clean/report.pdf', 'storage_key' => 'clean/report.pdf', 'disk' => 's3', 'mime_type' => 'application/pdf', 'file_size' => 123, 'scan_status' => 'clean']);

        $response = $this->get("/api/articles/assets/{$asset->id}/download?stream=1")->assertRedirect();
        $this->assertStringContainsString('clean/report.pdf', $response->headers->get('location'));
    }
}
