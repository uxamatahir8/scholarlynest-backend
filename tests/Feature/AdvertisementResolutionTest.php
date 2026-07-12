<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\Media;
use App\Models\User;
use App\Services\AdvertisementPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdvertisementResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['media_uploads.disk' => 's3']);
        $this->user = User::factory()->create();
        $this->media = Media::create(['filename' => 'ad.png', 'safe_original_name' => 'ad.png', 'url' => '', 'storage_key' => 'clean/advertisements/ad.png', 'disk' => 's3', 'mime_type' => 'image/png', 'size' => 100, 'scan_status' => 'clean']);
        Storage::disk('s3')->put($this->media->storage_key, 'image');
    }

    public function test_website_ad_resolves_only_on_selected_page(): void
    {
        $this->ad(['target_area' => 'website', 'target_mode' => 'single_page', 'page_key' => 'home']);
        $service = app(AdvertisementPlacementService::class);
        $this->assertCount(1, $service->forWebsitePage('home')['content_top']);
        $this->assertCount(0, $service->forWebsitePage('about')['content_top']);
    }

    public function test_article_targets_are_publication_scoped_and_priority_ordered(): void
    {
        $magazine = Magazine::create(['title' => 'Magazine', 'slug' => 'magazine', 'publication_type' => 'magazine']);
        $other = Magazine::create(['title' => 'Other', 'slug' => 'other', 'publication_type' => 'magazine']);
        $article = $this->article($magazine, 'selected');
        $otherArticle = $this->article($other, 'other');
        $low = $this->ad(['target_area' => 'article', 'target_mode' => 'all_articles', 'publication_type' => 'magazine', 'publication_id' => $magazine->id], 1, 'sidebar_sticky');
        $high = $this->ad(['target_area' => 'article', 'target_mode' => 'specific_articles', 'publication_type' => 'magazine', 'publication_id' => $magazine->id, 'article_id' => $article->id], 20, 'sidebar_sticky');
        $resolved = app(AdvertisementPlacementService::class)->forArticlePage($article)['sidebar_sticky'];
        $this->assertSame([$high->id, $low->id], array_column($resolved, 'id'));
        $this->assertCount(0, app(AdvertisementPlacementService::class)->forArticlePage($otherArticle)['sidebar_sticky']);
    }

    public function test_inactive_expired_and_future_ads_are_not_public(): void
    {
        $target = ['target_area' => 'website', 'target_mode' => 'single_page', 'page_key' => 'home'];
        $this->ad($target, 0, 'content_top', ['status' => 'inactive']);
        $this->ad($target, 0, 'content_top', ['ends_at' => now()->subMinute()]);
        $this->ad($target, 0, 'content_top', ['starts_at' => now()->addMinute()]);
        $this->assertCount(0, app(AdvertisementPlacementService::class)->forWebsitePage('home')['content_top']);
    }

    private function ad(array $target, int $priority = 0, string $placement = 'content_top', array $overrides = []): Advertisement
    {
        $ad = Advertisement::create(array_merge(['title' => 'Ad '.$priority, 'image_media_id' => $this->media->id, 'placement' => $placement, 'status' => 'active', 'priority' => $priority, 'created_by' => $this->user->id], $overrides));
        $ad->targets()->create($target);
        return $ad;
    }

    private function article(Magazine $magazine, string $slug): Article
    {
        return Article::create(['magazine_id' => $magazine->id, 'user_id' => $this->user->id, 'title' => ucfirst($slug), 'slug' => $slug, 'abstract' => 'Abstract', 'full_text' => 'Text', 'status' => 'published']);
    }
}
