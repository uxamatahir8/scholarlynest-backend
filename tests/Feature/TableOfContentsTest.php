<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleAuthor;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableOfContentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_table_of_contents_groups_and_sorts_by_article_published_at(): void
    {
        $magazine = Magazine::create(['title' => 'Publication Date Magazine', 'slug' => 'publication-date-magazine']);
        $user = User::factory()->create(['email' => 'private-author@example.com']);
        $issue2025 = MagazineIssue::create([
            'magazine_id' => $magazine->id,
            'volume_number' => 4,
            'issue_number' => 1,
            'issue_month' => 'August',
            'issue_year' => 2025,
            'special_title' => 'Issue metadata from 2025',
            'is_published' => true,
            'published_at' => Carbon::parse('2025-08-01 00:00:00'),
        ]);

        $octoberLater = $this->publishedArticle($magazine, $user, $issue2025, [
            'title' => 'Later October Article',
            'slug' => 'later-october-article',
            'published_at' => Carbon::parse('2026-10-20 12:00:00'),
            'pdf_path' => 'private/path/later.pdf',
        ]);
        $octoberEarlier = $this->publishedArticle($magazine, $user, $issue2025, [
            'title' => 'Earlier October Article',
            'slug' => 'earlier-october-article',
            'published_at' => Carbon::parse('2026-10-15 12:00:00'),
        ]);
        $this->publishedArticle($magazine, $user, null, [
            'title' => 'December Article',
            'slug' => 'december-article',
            'published_at' => Carbon::parse('2026-12-01 12:00:00'),
        ]);
        $this->publishedArticle($magazine, $user, null, [
            'title' => 'August Article',
            'slug' => 'august-article',
            'published_at' => Carbon::parse('2025-08-01 12:00:00'),
        ]);
        $this->publishedArticle($magazine, $user, null, [
            'title' => 'Draft Article',
            'slug' => 'draft-article',
            'status' => 'submitted',
            'published_at' => Carbon::parse('2026-12-15 12:00:00'),
        ]);

        ArticleAuthor::create([
            'article_id' => $octoberLater->id,
            'co_author_name' => 'Public Author',
            'co_author_email' => 'hidden@example.com',
            'author_order' => 1,
            'is_corresponding' => true,
        ]);

        $response = $this->getJson('/api/magazines/publication-date-magazine/table-of-contents');

        $response->assertOk()
            ->assertJsonPath('table_of_contents.2026.year', 2026)
            ->assertJsonPath('table_of_contents.2026.months.12.month', 12)
            ->assertJsonPath('table_of_contents.2026.months.12.month_name', 'December')
            ->assertJsonPath('table_of_contents.2026.months.10.month', 10)
            ->assertJsonPath('table_of_contents.2026.months.10.month_name', 'October')
            ->assertJsonPath('table_of_contents.2025.months.08.month', 8)
            ->assertJsonPath('table_of_contents.2026.months.10.articles.0.slug', 'later-october-article')
            ->assertJsonPath('table_of_contents.2026.months.10.articles.1.slug', 'earlier-october-article')
            ->assertJsonPath('table_of_contents.2026.months.10.articles.0.published_year', 2026)
            ->assertJsonPath('table_of_contents.2026.months.10.articles.0.published_month', 10)
            ->assertJsonPath('table_of_contents.2026.months.10.articles.0.published_month_name', 'October')
            ->assertJsonPath('table_of_contents.2026.months.10.articles.0.has_pdf', true)
            ->assertJsonPath('table_of_contents.2026.months.10.articles.0.issue.issue_year', 2025)
            ->assertJsonPath('table_of_contents.2026.months.10.articles.0.article_authors.0.co_author_name', 'Public Author')
            ->assertJsonMissing(['slug' => 'draft-article'])
            ->assertJsonMissing(['email' => 'private-author@example.com'])
            ->assertJsonMissing(['co_author_email' => 'hidden@example.com'])
            ->assertJsonMissing(['pdf_path' => 'private/path/later.pdf'])
            ->assertJsonMissing(['status' => 'published'])
            ->assertJsonMissing(['full_text' => 'Private full text']);

        $toc = $response->json('table_of_contents');
        $this->assertSame([2026, 2025], array_keys($toc));
        $this->assertSame([12, 10], array_keys($toc[2026]['months']));
    }

    public function test_public_table_of_contents_returns_empty_group_for_magazine_without_published_articles(): void
    {
        Magazine::create(['title' => 'Empty Magazine', 'slug' => 'empty-magazine']);

        $response = $this->getJson('/api/magazines/empty-magazine/table-of-contents');

        $response->assertOk()
            ->assertJsonPath('magazine.slug', 'empty-magazine')
            ->assertJsonPath('table_of_contents', []);
    }

    private function publishedArticle(Magazine $magazine, User $user, ?MagazineIssue $issue, array $overrides): Article
    {
        return Article::create(array_merge([
            'magazine_id' => $magazine->id,
            'magazine_issue_id' => $issue?->id,
            'user_id' => $user->id,
            'title' => 'Article',
            'slug' => 'article',
            'abstract' => 'Public abstract',
            'full_text' => 'Private full text',
            'status' => 'published',
        ], $overrides));
    }
}
