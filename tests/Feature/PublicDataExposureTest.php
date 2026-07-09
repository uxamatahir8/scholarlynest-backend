<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAsset;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\MagazineIssue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicDataExposureTest extends TestCase
{
    use RefreshDatabase;

    private Magazine $magazine;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->magazine = Magazine::create([
            'title' => 'Public Security Magazine',
            'slug' => 'public-security-magazine',
        ]);

        $this->author = User::factory()->create([
            'name' => 'Dr. Public Author',
            'email' => 'private-author@example.test',
        ]);
    }

    public function test_public_latest_articles_exposes_only_public_article_fields(): void
    {
        $article = $this->publishedArticle([
            'full_text' => 'Confidential manuscript body',
            'pdf_path' => 'storage/articles/private.pdf',
        ]);

        DB::table('article_author')->insert([
            'article_id' => $article->id,
            'user_id' => $this->author->id,
            'co_author_name' => 'Private Coauthor',
            'co_author_email' => 'private-coauthor@example.test',
            'can_edit' => true,
            'author_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ArticleAsset::create([
            'article_id' => $article->id,
            'file_path' => 'storage/assets/private-supplement.pdf',
            'original_filename' => 'supplement.pdf',
            'file_size' => 128,
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->getJson('/api/articles/latest');

        $response->assertOk()
            ->assertJsonPath('data.0.title', $article->title)
            ->assertJsonMissing(['status' => ArticleStatus::PUBLISHED])
            ->assertJsonMissing(['full_text' => 'Confidential manuscript body'])
            ->assertJsonMissing(['pdf_path' => 'storage/articles/private.pdf'])
            ->assertJsonMissing(['email' => 'private-author@example.test'])
            ->assertJsonMissing(['co_author_email' => 'private-coauthor@example.test'])
            ->assertJsonMissing(['can_edit' => true])
            ->assertJsonMissing(['file_path' => 'storage/assets/private-supplement.pdf']);
    }

    public function test_public_article_detail_hides_workflow_fields_and_requires_published_status(): void
    {
        $published = $this->publishedArticle([
            'slug' => 'public-detail',
            'pdf_path' => 'storage/articles/private-detail.pdf',
        ]);

        DB::table('article_author')->insert([
            'article_id' => $published->id,
            'user_id' => $this->author->id,
            'co_author_name' => 'Detail Coauthor',
            'co_author_email' => 'detail-coauthor@example.test',
            'can_edit' => true,
            'author_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ArticleAsset::create([
            'article_id' => $published->id,
            'file_path' => 'storage/assets/private-detail.xlsx',
            'original_filename' => 'supplement.xlsx',
            'file_size' => 256,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        $accepted = $this->publishedArticle([
            'title' => 'Accepted Private Detail',
            'slug' => 'accepted-private-detail',
            'status' => ArticleStatus::ACCEPTED,
        ]);

        $this->getJson('/api/articles/public-detail')
            ->assertOk()
            ->assertJsonPath('article.has_pdf', true)
            ->assertJsonPath('article.article_authors.0.co_author_name', 'Detail Coauthor')
            ->assertJsonMissing(['status' => ArticleStatus::PUBLISHED])
            ->assertJsonMissing(['pdf_path' => 'storage/articles/private-detail.pdf'])
            ->assertJsonMissing(['email' => 'private-author@example.test'])
            ->assertJsonMissing(['co_author_email' => 'detail-coauthor@example.test'])
            ->assertJsonMissing(['can_edit' => true])
            ->assertJsonMissing(['file_path' => 'storage/assets/private-detail.xlsx']);

        $this->getJson("/api/articles/{$accepted->slug}")->assertNotFound();
    }

    public function test_public_downloads_are_available_only_for_published_articles(): void
    {
        $published = $this->publishedArticle(['slug' => 'published-downloads']);
        $accepted = $this->publishedArticle([
            'slug' => 'accepted-downloads',
            'status' => ArticleStatus::ACCEPTED,
        ]);

        $publishedAsset = $this->assetFor($published, 'public-asset.pdf');
        $acceptedAsset = $this->assetFor($accepted, 'accepted-asset.pdf');
        $publishedFile = $this->articleFileFor($published, ArticleFile::SUPPLEMENTARY, 'public-file.pdf');
        $publishedWorkflowFile = $this->articleFileFor($published, ArticleFile::REVIEWED_MANUSCRIPT, 'reviewed-private.pdf');
        $acceptedFile = $this->articleFileFor($accepted, ArticleFile::SUPPLEMENTARY, 'accepted-file.pdf');

        $pdfPath = Storage::disk('public')->putFile('articles', UploadedFile::fake()->create('public.pdf', 16, 'application/pdf'));
        $published->update(['pdf_path' => 'storage/' . $pdfPath]);

        $acceptedPdfPath = Storage::disk('public')->putFile('articles', UploadedFile::fake()->create('accepted.pdf', 16, 'application/pdf'));
        $accepted->update(['pdf_path' => 'storage/' . $acceptedPdfPath]);

        $this->get("/api/articles/assets/{$publishedAsset->id}/download")->assertOk();
        $this->get("/api/articles/files/{$publishedFile->id}/download")->assertOk();
        $this->get("/api/articles/{$published->id}/download-pdf")->assertOk();
        $this->getJson("/api/articles/files/{$publishedWorkflowFile->id}/download")->assertForbidden();

        $this->getJson("/api/articles/assets/{$acceptedAsset->id}/download")->assertForbidden();
        $this->getJson("/api/articles/files/{$acceptedFile->id}/download")->assertForbidden();
        $this->getJson("/api/articles/{$accepted->id}/download-pdf")->assertNotFound()
            ->assertJsonPath('message', 'The requested file is not available.');
    }

    public function test_public_homepage_stats_return_safe_published_aggregates_only(): void
    {
        $secondMagazine = Magazine::create([
            'title' => 'Second Public Magazine',
            'slug' => 'second-public-magazine',
        ]);

        $publishedIssue = MagazineIssue::create([
            'magazine_id' => $this->magazine->id,
            'volume_number' => '1',
            'issue_number' => '1',
            'issue_month' => 'June',
            'issue_year' => '2026',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);

        MagazineIssue::create([
            'magazine_id' => $secondMagazine->id,
            'volume_number' => '1',
            'issue_number' => '2',
            'issue_month' => 'July',
            'issue_year' => '2026',
            'status' => 'draft',
            'is_published' => false,
        ]);

        $published = $this->publishedArticle([
            'title' => 'Published Aggregate Article',
            'magazine_issue_id' => $publishedIssue->id,
        ]);

        $this->publishedArticle([
            'title' => 'Unpublished Aggregate Article',
            'status' => ArticleStatus::ACCEPTED,
            'magazine_id' => $secondMagazine->id,
        ]);

        DB::table('article_author')->insert([
            'article_id' => $published->id,
            'user_id' => $this->author->id,
            'co_author_name' => 'Aggregate Coauthor',
            'co_author_email' => 'aggregate-private@example.test',
            'can_edit' => true,
            'author_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/public/homepage-stats');

        $response->assertOk()
            ->assertJsonPath('published_articles_count', 1)
            ->assertJsonPath('active_magazines_count', 2)
            ->assertJsonPath('published_issues_count', 1)
            ->assertJsonPath('public_contributors_count', 2)
            ->assertJsonMissing(['Unpublished Aggregate Article'])
            ->assertJsonMissing(['aggregate-private@example.test'])
            ->assertJsonMissing(['can_edit']);
    }

    public function test_public_custom_page_response_excludes_internal_page_metadata(): void
    {
        $editor = User::factory()->create();

        MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Editorial Policy',
            'slug' => 'editorial-policy',
            'content' => 'Public policy text',
            'status' => 'active',
            'created_by' => $editor->id,
            'created_by_role' => 'editor',
            'is_editor_created' => true,
            'sort_order' => 3,
        ]);

        $this->getJson('/api/magazines/public-security-magazine/pages/editorial-policy')
            ->assertOk()
            ->assertJsonPath('page.content', 'Public policy text')
            ->assertJsonMissing(['created_by' => $editor->id])
            ->assertJsonMissing(['created_by_role' => 'editor'])
            ->assertJsonMissing(['is_editor_created' => true])
            ->assertJsonMissing(['sort_order' => 3]);
    }

    public function test_public_not_found_responses_do_not_include_exception_details(): void
    {
        $this->getJson('/api/articles/does-not-exist')
            ->assertNotFound()
            ->assertJsonMissing(['exception'])
            ->assertJsonMissing(['trace'])
            ->assertJsonMissing(['file'])
            ->assertJsonMissing(['line']);
    }

    private function publishedArticle(array $overrides = []): Article
    {
        return Article::create(array_merge([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Public Article',
            'slug' => 'public-article-' . uniqid(),
            'abstract' => 'Public abstract',
            'full_text' => 'Public body',
            'status' => ArticleStatus::PUBLISHED,
            'published_at' => now(),
        ], $overrides));
    }

    private function assetFor(Article $article, string $filename): ArticleAsset
    {
        $path = Storage::disk('public')->putFile('assets', UploadedFile::fake()->create($filename, 16, 'application/pdf'));

        return ArticleAsset::create([
            'article_id' => $article->id,
            'file_path' => 'storage/' . $path,
            'original_filename' => $filename,
            'file_size' => 16,
            'mime_type' => 'application/pdf',
        ]);
    }

    private function articleFileFor(Article $article, string $type, string $filename): ArticleFile
    {
        $path = Storage::disk('public')->putFile("article-files/{$article->id}", UploadedFile::fake()->create($filename, 16, 'application/pdf'));

        return ArticleFile::create([
            'article_id' => $article->id,
            'uploaded_by' => $this->author->id,
            'file_type' => $type,
            'visibility' => 'author_visible',
            'file_path' => 'storage/' . $path,
            'original_name' => $filename,
            'mime_type' => 'application/pdf',
            'size' => 16,
        ]);
    }
}
