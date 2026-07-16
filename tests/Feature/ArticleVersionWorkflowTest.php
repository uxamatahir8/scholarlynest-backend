<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\MediaUploadSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleVersionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private User $editor;
    private Magazine $magazine;

    protected function setUp(): void
    {
        parent::setUp();

        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);

        foreach (['articles.create', 'articles.view-own', 'articles.edit-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['module' => 'articles', 'description' => $permission]);
        }

        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.create', 'articles.view-own', 'articles.edit-own'])->pluck('id'));
        $editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));

        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->magazine = Magazine::create([
            'title' => 'Version Magazine',
            'slug' => 'version-magazine',
            'description' => 'Version test magazine',
        ]);
        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);

        Storage::fake('public');
    }

    public function test_initial_submission_creates_version_and_links_manuscript_file(): void
    {
        Sanctum::actingAs($this->author);

        $articleId = $this->post('/api/articles', [
            'magazine_id' => $this->magazine->id,
            'title' => 'Versioned Submission',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'terms_accepted' => true,
            'pdf_upload_id' => $this->cleanUpload($this->author, 'article_manuscript', 'manuscript.pdf')->id,
        ])->assertStatus(211)->json('article.id');

        $this->assertDatabaseHas('article_versions', [
            'article_id' => $articleId,
            'version_number' => 1,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
        ]);

        $version = ArticleVersion::where('article_id', $articleId)->firstOrFail();
        $this->assertDatabaseHas('article_files', [
            'article_id' => $articleId,
            'article_version_id' => $version->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'original_name' => 'manuscript.pdf',
        ]);
    }

    public function test_author_resubmission_creates_next_version_with_revision_notes(): void
    {
        $article = $this->articleWithStatus(ArticleStatus::REVISION_REQUIRED);
        ArticleVersion::create([
            'article_id' => $article->id,
            'created_by' => $this->author->id,
            'version_number' => 1,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
            'metadata_snapshot' => [],
        ]);

        Sanctum::actingAs($this->author);

        $this->post("/api/admin/articles/{$article->id}", [
            '_method' => 'PUT',
            'magazine_id' => $article->magazine_id,
            'title' => 'Revised Versioned Submission',
            'abstract' => $article->abstract,
            'full_text' => $article->full_text,
            'change_summary' => 'Updated methods and discussion.',
            'pdf_upload_id' => $this->cleanUpload($this->author, 'article_revision', 'revised.pdf', $article)->id,
            'revision_response_upload_id' => $this->cleanUpload($this->author, 'article_revision_response', 'response.docx', $article)->id,
        ])->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::RESUBMITTED);

        $this->assertDatabaseHas('article_versions', [
            'article_id' => $article->id,
            'version_number' => 2,
            'label' => 'Revised Manuscript',
            'status_snapshot' => ArticleStatus::RESUBMITTED,
            'change_summary' => 'Updated methods and discussion.',
            'author_response' => null,
        ]);
        $this->assertDatabaseHas('article_files', [
            'article_id' => $article->id,
            'file_type' => ArticleFile::REVISION_RESPONSE,
            'original_name' => 'response.docx',
        ]);
    }

    public function test_acceptance_marks_submitted_version_and_creates_accepted_file_set(): void
    {
        $article = $this->articleWithStatus(ArticleStatus::REVIEW_IN_PROGRESS);
        $version = ArticleVersion::create([
            'article_id' => $article->id,
            'created_by' => $this->author->id,
            'version_number' => 1,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
        ]);
        $file = ArticleFile::create([
            'article_id' => $article->id,
            'article_version_id' => $version->id,
            'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'visibility' => 'author_visible',
            'disk' => 's3',
            'file_path' => 'clean/accepted-manuscript.pdf',
            'storage_key' => 'clean/accepted-manuscript.pdf',
            'original_name' => 'accepted-manuscript.pdf',
            'mime_type' => 'application/pdf',
            'size' => 14,
            'scan_status' => 'clean',
        ]);

        Sanctum::actingAs($this->editor);

        $this->postJson("/api/admin/articles/{$article->id}/final-decision", [
            'decision' => 'accepted',
            'decision_source' => 'editor_personal_review',
            'comments_for_author' => 'Accepted after revisions.',
        ])->assertCreated();

        $this->assertDatabaseHas('article_accepted_file_sets', [
            'article_id' => $article->id,
            'article_version_id' => $version->id,
            'accepted_by' => $this->editor->id,
        ]);
        $this->assertDatabaseHas('article_accepted_file_set_items', [
            'article_file_id' => $file->id,
            'source_version_id' => $version->id,
            'accepted_role' => 'manuscript',
        ]);
        $this->assertNotNull($version->fresh()->accepted_at);

        $this->getJson("/api/admin/articles/{$article->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Initial Submission')
            ->assertJsonPath('data.0.accepted_by', $this->editor->id);
    }

    private function articleWithStatus(string $status): Article
    {
        return Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Version Article',
            'slug' => 'version-article-' . str_replace('_', '-', $status),
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }

    private function cleanUpload(User $user, string $purpose, string $filename, ?Article $article = null): MediaUploadSession
    {
        $key = 'dev/clean/test/' . $purpose . '/' . $filename;
        Storage::disk('s3')->put($key, "%PDF-1.4\n%%EOF");

        return MediaUploadSession::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'attachable_type' => $article ? Article::class : null,
            'attachable_id' => $article?->id,
            'original_filename' => $filename,
            'safe_display_filename' => $filename,
            'expected_size_bytes' => 14,
            'declared_mime_type' => 'application/pdf',
            'disk' => 's3',
            's3_incoming_key' => 'dev/incoming/test/' . $purpose . '/' . $filename,
            's3_clean_key' => $key,
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_CLEAN,
            'detected_mime_type' => 'application/pdf',
            'checksum_sha256' => str_repeat('b', 64),
            'scan_engine' => 'fake-clamav',
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }
}
