<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvertisementManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'admin']);
        $permission = Permission::create(['name' => 'advertisements.manage', 'module' => 'advertisements']);
        $role->permissions()->attach($permission);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->media = Media::create(['filename' => 'ad.png', 'url' => '', 'storage_key' => 'clean/advertisements/ad.png', 'disk' => 's3', 'mime_type' => 'image/png', 'size' => 100, 'scan_status' => 'clean']);
        Sanctum::actingAs($this->admin);
    }

    public function test_published_article_selectors_exclude_non_published_magazine_and_journal_articles(): void
    {
        foreach (['magazine', 'journal'] as $type) {
            $publication = Magazine::create(['title' => ucfirst($type), 'slug' => $type, 'publication_type' => $type]);
            $published = $this->article($publication, "$type-published", 'published');
            $this->article($publication, "$type-accepted", 'accepted');
            $this->article($publication, "$type-draft", 'draft');

            $this->getJson("/api/admin/advertisements/publications/{$publication->id}/published-articles")
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $published->id);
        }
    }

    public function test_specific_non_published_magazine_and_journal_article_targets_are_rejected(): void
    {
        foreach (['magazine', 'journal'] as $type) {
            $publication = Magazine::create(['title' => ucfirst($type), 'slug' => "reject-$type", 'publication_type' => $type]);
            $draft = $this->article($publication, "$type-private", 'accepted');

            $this->postJson('/api/admin/advertisements', $this->payload($publication, $draft))
                ->assertUnprocessable()->assertJsonValidationErrors('targets.0.article_id');
        }
    }

    public function test_specific_published_magazine_and_journal_article_targets_are_accepted(): void
    {
        foreach (['magazine', 'journal'] as $type) {
            $publication = Magazine::create(['title' => ucfirst($type), 'slug' => "accept-$type", 'publication_type' => $type]);
            $published = $this->article($publication, "$type-public", 'published');

            $this->postJson('/api/admin/advertisements', $this->payload($publication, $published))
                ->assertCreated()->assertJsonPath('targets.0.article_id', $published->id);
        }
    }

    public function test_all_published_article_targets_are_accepted_for_magazines_and_journals(): void
    {
        foreach (['magazine', 'journal'] as $type) {
            $publication = Magazine::create(['title' => "All $type", 'slug' => "all-$type", 'publication_type' => $type]);
            $payload = $this->payload($publication, $this->article($publication, "$type-seed", 'published'));
            $payload['targets'][0]['target_mode'] = 'all_articles';
            $payload['targets'][0]['article_id'] = null;

            $this->postJson('/api/admin/advertisements', $payload)
                ->assertCreated()->assertJsonPath('targets.0.target_mode', 'all_articles');
        }
    }

    private function payload(Magazine $publication, Article $article): array
    {
        return ['title' => 'Published article ad', 'image_media_id' => $this->media->id, 'placement' => 'sidebar_sticky', 'status' => 'active', 'targets' => [[
            'target_area' => 'article', 'target_mode' => 'specific_articles', 'publication_type' => $publication->publication_type,
            'publication_id' => $publication->id, 'article_id' => $article->id,
        ]]];
    }

    private function article(Magazine $publication, string $slug, string $status): Article
    {
        return Article::create(['magazine_id' => $publication->id, 'user_id' => $this->admin->id, 'title' => ucfirst($slug), 'slug' => $slug, 'abstract' => 'Abstract', 'full_text' => 'Text', 'status' => $status]);
    }
}
