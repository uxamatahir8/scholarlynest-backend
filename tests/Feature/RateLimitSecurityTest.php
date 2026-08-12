<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAsset;
use App\Models\Magazine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RateLimitSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rate_limit_returns_safe_429_response(): void
    {
        User::factory()->create(['email' => 'limited@example.test']);

        $response = null;
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'limited@example.test',
                'password' => 'wrong-password',
            ], ['REMOTE_ADDR' => '203.0.113.10']);
        }

        $response->assertStatus(429);
        $json = $response->getContent();
        foreach (['exception', 'trace', 'SQLSTATE', '/home/', 'password', 'token', 'secret'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_public_download_rate_limit_returns_safe_429_response(): void
    {
        Storage::fake('public');
        $magazine = Magazine::create(['title' => 'Rate Magazine', 'slug' => 'rate-magazine']);
        $author = User::factory()->create();
        $article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $author->id,
            'title' => 'Rate Limited Article',
            'slug' => 'rate-limited-article',
            'abstract' => 'Abstract',
            'full_text' => 'Body',
            'status' => ArticleStatus::PUBLISHED,
            'published_at' => now(),
        ]);
        $path = Storage::disk('public')->putFile('assets', UploadedFile::fake()->create('rate.pdf', 16, 'application/pdf'));
        $asset = ArticleAsset::create([
            'article_id' => $article->id,
            'file_path' => 'storage/' . $path,
            'original_filename' => 'rate.pdf',
            'file_size' => 16,
            'mime_type' => 'application/pdf',
        ]);

        $response = null;
        for ($i = 0; $i < 61; $i++) {
            $response = $this->getJson("/api/articles/assets/{$asset->id}/download", ['REMOTE_ADDR' => '203.0.113.20']);
        }

        $response->assertStatus(429);
        $json = $response->getContent();
        foreach (['exception', 'trace', 'SQLSTATE', '/home/', 'file_path', 'storage/', 'token', 'secret'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }
}
