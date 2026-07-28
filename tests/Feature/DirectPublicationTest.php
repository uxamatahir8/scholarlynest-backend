<?php

namespace Tests\Feature;

use App\Http\Controllers\ArticleFileController;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DirectPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $publisher;

    private User $editor;

    private Magazine $magazine;

    private Magazine $otherMagazine;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $superRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $publisherRole = Role::create(['name' => 'publisher', 'display_name' => 'Publisher', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $this->superAdmin = User::factory()->create(['role_id' => $superRole->id]);
        $this->publisher = User::factory()->create(['role_id' => $publisherRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->magazine = Magazine::create(['title' => 'Direct Journal', 'slug' => 'direct-journal', 'description' => 'Test', 'publication_type' => 'journal', 'is_active' => true]);
        $this->otherMagazine = Magazine::create(['title' => 'Other Journal', 'slug' => 'other-journal', 'description' => 'Test', 'publication_type' => 'journal', 'is_active' => true]);
        $this->publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);
    }

    public function test_only_super_admin_and_scoped_publisher_can_create_direct_drafts(): void
    {
        Sanctum::actingAs($this->editor);
        $this->withHeader('Idempotency-Key', 'editor-denied')->postJson('/api/admin/direct-publications', $this->payload($this->magazine))->assertForbidden();

        Sanctum::actingAs($this->publisher);
        $this->withHeader('Idempotency-Key', 'publisher-out-of-scope')->postJson('/api/admin/direct-publications', $this->payload($this->otherMagazine))->assertForbidden();
        $response = $this->withHeader('Idempotency-Key', 'publisher-create')->postJson('/api/admin/direct-publications', $this->payload($this->magazine))->assertCreated();
        $articleId = $response->json('data.id');
        $this->withHeader('Idempotency-Key', 'publisher-not-ready')->postJson("/api/admin/direct-publications/{$articleId}/mark-ready")
            ->assertUnprocessable()->assertJsonPath('code', 'DIRECT_PUBLICATION_NOT_READY');

        $this->assertDatabaseHas('articles', ['id' => $articleId, 'submission_mode' => 'direct_publication', 'status' => 'direct_publication_draft']);
        $this->assertDatabaseHas('article_versions', ['article_id' => $articleId, 'version_number' => 1, 'revision_number' => 0, 'source' => 'direct_publication']);
        $this->assertDatabaseHas('publication_records', ['article_id' => $articleId, 'publication_mode' => 'direct', 'accepted_file_set_id' => null]);
        $this->assertDatabaseCount('reviewer_assignments', 0);
        $this->assertDatabaseCount('editorial_decisions', 0);
        $this->assertDatabaseCount('proof_rounds', 0);
        $this->assertDatabaseCount('production_assignments', 0);
    }

    public function test_readiness_publish_and_unpublish_use_only_explicit_public_files(): void
    {
        $issue = MagazineIssue::create(['magazine_id' => $this->magazine->id, 'volume_number' => 4, 'issue_number' => 2, 'issue_year' => now()->year, 'status' => 'published', 'is_published' => true, 'published_at' => now()]);
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/admin/direct-publications/options')
            ->assertOk()
            ->assertJsonPath('data.issues.0.id', $issue->id)
            ->assertJsonPath('data.issues.0.status', 'published');
        $payload = $this->payload($this->magazine) + ['magazine_issue_id' => $issue->id, 'doi' => '10.5555/direct.1', 'page_start' => 1, 'page_end' => 8, 'online_publication_date' => now()->toDateString(),
            'publication_sections' => [
                ['section_key' => 'abstract', 'title' => 'Summary', 'content_html' => '<p>Direct summary.</p>', 'sort_order' => 1],
                ['section_key' => 'introduction', 'title' => 'Introduction', 'content_html' => '<p>Introduction text.</p>', 'sort_order' => 2],
                ['section_key' => 'custom_analysis', 'title' => 'Custom Analysis', 'content_html' => '<p>Custom content.</p>', 'sort_order' => 3],
            ]];
        $article = Article::findOrFail($this->withHeader('Idempotency-Key', 'direct-ready-create')->postJson('/api/admin/direct-publications', $payload)->assertCreated()->json('data.id'));
        $pdf = ArticleFile::create(['article_id' => $article->id, 'article_version_id' => $article->current_version_id, 'uploaded_by' => $this->superAdmin->id,
            'file_type' => ArticleFile::DIRECT_PUBLICATION_MANUSCRIPT, 'visibility' => 'internal', 'disk' => 'local', 'file_path' => 'direct/article.pdf', 'storage_key' => 'direct/article.pdf',
            'original_name' => 'article.pdf', 'safe_original_name' => 'article.pdf', 'mime_type' => 'application/pdf', 'size' => 100, 'scan_status' => 'clean']);
        $source = ArticleFile::create(['article_id' => $article->id, 'article_version_id' => $article->current_version_id, 'uploaded_by' => $this->superAdmin->id,
            'file_type' => ArticleFile::DIRECT_PUBLICATION_SOURCE, 'visibility' => 'internal', 'disk' => 'local', 'file_path' => 'direct/source.docx', 'storage_key' => 'direct/source.docx',
            'original_name' => 'source.docx', 'safe_original_name' => 'source.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 100, 'scan_status' => 'clean']);
        $figure = ArticleFile::create(['article_id' => $article->id, 'article_version_id' => $article->current_version_id, 'uploaded_by' => $this->superAdmin->id,
            'file_type' => ArticleFile::DIRECT_PUBLICATION_FIGURE, 'visibility' => 'internal', 'disk' => 'local', 'file_path' => 'direct/figure.png', 'storage_key' => 'direct/figure.png',
            'original_name' => 'figure.png', 'safe_original_name' => 'figure.png', 'mime_type' => 'image/png', 'size' => 100, 'scan_status' => 'clean']);

        $this->assertDatabaseCount('article_publication_sections', 3);
        $this->withHeader('Idempotency-Key', 'select-primary')->postJson("/api/admin/direct-publications/{$article->id}/select-primary-file", ['article_file_id' => $pdf->id])->assertOk();
        $this->withHeader('Idempotency-Key', 'direct-file-visibility')->postJson("/api/admin/direct-publications/{$article->id}/public-assets", [
            'publication_file_settings' => [
                ['file_id' => $pdf->id, 'include_in_package' => true],
                ['file_id' => $source->id, 'include_in_package' => true],
                ['file_id' => $figure->id, 'show_on_article' => true, 'show_in_downloads' => true, 'include_in_package' => true],
            ],
        ])->assertOk();
        $this->withHeader('Idempotency-Key', 'mark-ready')->postJson("/api/admin/direct-publications/{$article->id}/mark-ready")->assertOk()->assertJsonPath('data.status', 'direct_publication_ready');
        $this->withHeader('Idempotency-Key', 'publish-now')->postJson("/api/admin/direct-publications/{$article->id}/publish", ['confirmed' => true])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertTrue(app(ArticleFileController::class)->canAccess(null, $pdf->fresh('article')));
        $this->assertTrue(app(ArticleFileController::class)->canAccess(null, $figure->fresh('article')));
        $this->assertFalse(app(ArticleFileController::class)->canAccess(null, $source->fresh('article')));
        $this->withHeader('Idempotency-Key', 'unpublish-now')->postJson("/api/admin/direct-publications/{$article->id}/unpublish", ['reason' => 'A documented legal correction is required.'])->assertOk()->assertJsonPath('data.status', 'unpublished');
        $this->assertFalse(app(ArticleFileController::class)->canAccess(null, $pdf->fresh('article')));
    }

    private function payload(Magazine $magazine): array
    {
        return ['magazine_id' => $magazine->id, 'title' => 'A Directly Published Study', 'abstract' => 'A complete abstract.', 'keywords' => ['direct', 'publication'],
            'article_type' => 'Research Article', 'article_category' => 'Research', 'language' => 'English',
            'ethical_approval_statement' => 'Not applicable.', 'conflict_of_interest_statement' => 'None declared.', 'funding_statement' => 'No external funding.',
            'data_availability_statement' => 'Data are available on request.', 'author_contribution_statement' => 'All authors contributed.', 'license_statement' => 'CC BY 4.0',
            'authors' => [['name' => 'Ada Researcher', 'email' => 'ada@example.test', 'affiliation' => 'Scholarly Institute', 'is_corresponding' => true]]];
    }
}
