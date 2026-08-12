<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\Role;
use App\Models\User;
use App\Services\SlugNormalizationService;
use App\Services\SlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlugLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private SlugService $slugs;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slugs = app(SlugService::class);
        $role = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $this->author = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_publication_and_article_generation_uses_clean_title_slug(): void
    {
        $magazine = $this->publication('Theriogenology', Magazine::TYPE_MAGAZINE);
        $journal = $this->publication('Journal of Food Science', Magazine::TYPE_JOURNAL);
        $article = $this->article($magazine, 'Usama Tahir is Testing Magazine 1');
        $journalArticle = $this->article($journal, 'Études, Food & Health!');

        $this->assertSame('theriogenology', $magazine->slug);
        $this->assertSame('journal-of-food-science', $journal->slug);
        $this->assertSame('usama-tahir-is-testing-magazine-1', $article->slug);
        $this->assertSame('etudes-food-health', $journalArticle->slug);
    }

    public function test_real_collisions_receive_readable_numeric_suffixes(): void
    {
        $first = $this->publication('Same Name');
        $second = $this->publication('Same Name');
        $third = $this->publication('Same Name', Magazine::TYPE_JOURNAL);
        $this->assertSame('same-name', $first->slug);
        $this->assertSame('same-name-2', $second->slug);
        $this->assertSame('same-name-3', $third->slug);

        $firstArticle = $this->article($first, 'Shared Article');
        $secondArticle = $this->article($first, 'Shared Article');
        $otherPublicationArticle = $this->article($second, 'Shared Article');
        $this->assertSame('shared-article', $firstArticle->slug);
        $this->assertSame('shared-article-2', $secondArticle->slug);
        $this->assertSame('shared-article', $otherPublicationArticle->slug);
    }

    public function test_database_enforces_article_slug_uniqueness_within_publication(): void
    {
        $publication = $this->publication('Constraint');
        $this->article($publication, 'Unique Article');
        $this->expectException(\Illuminate\Database\QueryException::class);
        Article::create([
            'magazine_id' => $publication->id, 'user_id' => $this->author->id, 'title' => 'Other',
            'slug' => 'unique-article', 'abstract' => 'Abstract', 'full_text' => '', 'status' => 'draft',
        ]);
    }

    public function test_dry_run_detects_proven_legacy_suffixes_without_mutation(): void
    {
        $publication = Magazine::create(['title' => 'Theriogenology', 'slug' => 'theriogenology-XH82d', 'publication_type' => 'magazine']);
        $article = Article::create([
            'magazine_id' => $publication->id, 'user_id' => $this->author->id, 'title' => 'Article Title',
            'slug' => 'article-title-l80ugu', 'abstract' => 'Abstract', 'full_text' => '', 'status' => 'draft',
        ]);
        $audit = app(SlugNormalizationService::class)->audit();
        $this->assertSame(2, $audit['summary']['legacy_random_slugs_found']);
        $this->assertSame('theriogenology-XH82d', $publication->fresh()->slug);
        $this->assertSame('article-title-l80ugu', $article->fresh()->slug);
    }

    public function test_apply_normalizes_collisions_creates_redirects_and_is_idempotent(): void
    {
        $clean = Magazine::create(['title' => 'Existing Clean', 'slug' => 'theriogenology', 'publication_type' => 'magazine']);
        $legacy = Magazine::create(['title' => 'Theriogenology', 'slug' => 'theriogenology-XH82d', 'publication_type' => 'magazine']);
        $normalizer = app(SlugNormalizationService::class);
        $first = $normalizer->apply($normalizer->audit());
        $second = $normalizer->apply($normalizer->audit());

        $this->assertSame('theriogenology-2', $legacy->fresh()->slug);
        $this->assertCount(1, $first['applied']);
        $this->assertCount(0, $second['applied']);
        $this->assertDatabaseHas('slug_redirects', ['entity_id' => $legacy->id, 'old_slug' => 'theriogenology-XH82d', 'new_slug' => 'theriogenology-2']);
    }

    public function test_legitimate_numeric_and_custom_slugs_are_preserved(): void
    {
        Magazine::create(['title' => 'COVID 19', 'slug' => 'covid-19', 'publication_type' => 'magazine']);
        Magazine::create(['title' => 'Editorial Policy', 'slug' => 'our-editorial-policy', 'publication_type' => 'journal']);
        $audit = app(SlugNormalizationService::class)->audit();
        $this->assertSame(0, $audit['summary']['legacy_random_slugs_found']);
    }

    public function test_ambiguous_generator_like_slug_requires_manual_review(): void
    {
        Magazine::create(['title' => 'Ambiguous', 'slug' => 'ambiguous-ab_cd', 'publication_type' => 'magazine']);
        $audit = app(SlugNormalizationService::class)->audit();
        $this->assertSame(1, $audit['summary']['manual_review_records']);
        $this->assertSame('MANUAL REVIEW', $audit['manual_reviews'][0]['action']);
    }

    public function test_resolver_handles_old_parent_and_child_combinations_without_loops(): void
    {
        $publication = Magazine::create(['title' => 'Magazine', 'slug' => 'theriogenology', 'publication_type' => 'magazine']);
        $article = Article::create([
            'magazine_id' => $publication->id, 'user_id' => $this->author->id, 'title' => 'Article',
            'slug' => 'usama-tahir-is-testing-magazine-1', 'abstract' => 'Abstract', 'full_text' => '', 'status' => 'published',
        ]);
        $this->slugs->recordRedirect('publication', $publication->id, 'theriogenology-XH82d', $publication->slug);
        $this->slugs->recordRedirect('article', $article->id, 'usama-tahir-is-testing-magazine-1-l80ugu', $article->slug, $publication->id);
        $target = '/magazines/theriogenology/articles/usama-tahir-is-testing-magazine-1';

        foreach ([
            ['theriogenology-XH82d', 'usama-tahir-is-testing-magazine-1-l80ugu'],
            ['theriogenology-XH82d', 'usama-tahir-is-testing-magazine-1'],
            ['theriogenology', 'usama-tahir-is-testing-magazine-1-l80ugu'],
        ] as [$parent, $child]) {
            $this->getJson('/api/slugs/resolve?publication_type=magazine&publication_slug='.$parent.'&article_slug='.$child)
                ->assertOk()->assertJsonPath('canonical_path', $target)->assertJsonPath('redirect_required', true);
        }
        $this->getJson('/api/slugs/resolve?publication_type=magazine&publication_slug=theriogenology&article_slug=usama-tahir-is-testing-magazine-1')
            ->assertOk()->assertJsonPath('redirect_required', false);
        $this->getJson('/api/slugs/resolve?publication_type=magazine&publication_slug=unknown')->assertNotFound();
    }

    public function test_sitemap_and_article_api_emit_only_canonical_scoped_urls(): void
    {
        $publication = $this->publication('Canonical Journal', Magazine::TYPE_JOURNAL);
        $article = $this->article($publication, 'Canonical Article');
        $article->update(['status' => 'published']);

        $this->getJson('/api/sitemap')->assertOk()
            ->assertJsonFragment(['path' => '/journals/canonical-journal/articles/canonical-article']);
        $this->getJson('/api/journals/canonical-journal/articles/canonical-article')->assertOk()
            ->assertJsonPath('article.slug', 'canonical-article')
            ->assertJsonPath('article.public_url', '/journals/canonical-journal/articles/canonical-article');
    }

    private function publication(string $title, string $type = Magazine::TYPE_MAGAZINE): Magazine
    {
        return $this->slugs->createPublication(['title' => $title, 'publication_type' => $type]);
    }

    private function article(Magazine $publication, string $title): Article
    {
        return $this->slugs->createArticle([
            'magazine_id' => $publication->id, 'user_id' => $this->author->id, 'title' => $title,
            'abstract' => 'Abstract', 'full_text' => '', 'status' => 'draft',
        ]);
    }
}
