<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\User;
use App\Services\ArticleTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalPublicationTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_compatible_publications_default_to_magazine(): void
    {
        $publication = Magazine::create(['title' => 'Legacy Publication', 'slug' => 'legacy-publication']);

        $this->assertSame(Magazine::TYPE_MAGAZINE, $publication->fresh()->publication_type);
        $this->assertTrue($publication->fresh()->isMagazine());
        $this->assertSame('magazines', $publication->publicRoutePrefix());
    }

    public function test_catalogs_and_public_shells_are_scoped_by_publication_type(): void
    {
        Magazine::create(['title' => 'Magazine One', 'slug' => 'magazine-one', 'publication_type' => 'magazine']);
        Magazine::create(['title' => 'Journal One', 'slug' => 'journal-one', 'publication_type' => 'journal']);

        $this->getJson('/api/magazines?publication_type=magazine')
            ->assertOk()->assertJsonFragment(['title' => 'Magazine One'])->assertJsonMissing(['title' => 'Journal One']);
        $this->getJson('/api/journals')
            ->assertOk()->assertJsonFragment(['title' => 'Journal One'])->assertJsonMissing(['title' => 'Magazine One']);
        $this->getJson('/api/journals/journal-one')->assertOk()->assertJsonPath('publication_type', 'journal');
        $this->getJson('/api/magazines/journal-one')->assertNotFound();
        $this->getJson('/api/journals/magazine-one')->assertNotFound();
    }

    public function test_public_article_route_and_url_follow_publication_type(): void
    {
        $journal = Magazine::create(['title' => 'Journal One', 'slug' => 'journal-one', 'publication_type' => 'journal']);
        $article = Article::create([
            'magazine_id' => $journal->id,
            'user_id' => User::factory()->create()->id,
            'title' => 'Journal Article',
            'slug' => 'journal-article',
            'abstract' => 'Abstract',
            'full_text' => 'Body',
            'status' => ArticleStatus::PUBLISHED,
        ]);

        $this->getJson('/api/journals/journal-one/articles/journal-article')
            ->assertOk()->assertJsonPath('article.public_url', '/journals/journal-one/articles/journal-article');
        $this->getJson('/api/magazines/journal-one/articles/journal-article')->assertNotFound();
    }

    public function test_transfer_targets_are_limited_to_same_publication_type(): void
    {
        $journal = Magazine::create(['title' => 'Journal One', 'slug' => 'journal-one', 'publication_type' => 'journal']);
        $journalTarget = Magazine::create(['title' => 'Journal Two', 'slug' => 'journal-two', 'publication_type' => 'journal']);
        $magazineTarget = Magazine::create(['title' => 'Magazine One', 'slug' => 'magazine-one', 'publication_type' => 'magazine']);
        $article = Article::create([
            'magazine_id' => $journal->id,
            'user_id' => User::factory()->create()->id,
            'title' => 'Transfer Article', 'slug' => 'transfer-article', 'abstract' => 'Abstract', 'full_text' => '',
            'status' => ArticleStatus::SCREENING,
        ]);

        $targets = app(ArticleTransferService::class)->getEligibleTargetMagazines($article);
        $this->assertTrue($targets->contains('id', $journalTarget->id));
        $this->assertFalse($targets->contains('id', $magazineTarget->id));
    }
}
