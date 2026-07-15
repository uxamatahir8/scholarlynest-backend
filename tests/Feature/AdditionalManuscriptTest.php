<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Http\Controllers\ArticleFileController;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MediaUploadSession;
use App\Models\Permission;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdditionalManuscriptTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Magazine $magazine;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        foreach (['articles.create', 'articles.view-own', 'articles.edit-own'] as $name) {
            Permission::firstOrCreate(['name' => $name], ['module' => 'articles', 'description' => $name]);
        }
        $role->permissions()->sync(Permission::pluck('id'));
        $this->author = User::factory()->create(['role_id' => $role->id]);
        $this->magazine = Magazine::create(['title' => 'Documents Journal', 'slug' => 'documents-journal']);
        Sanctum::actingAs($this->author);
    }

    public function test_initial_submission_persists_titled_additional_files_in_initial_version(): void
    {
        $cover = $this->cleanUpload('additional_manuscript_file', 'cover.pdf', null, 'Cover Letter');
        $ethics = $this->cleanUpload('additional_manuscript_file', 'ethics.docx', null, 'Ethics Approval');

        $articleId = $this->post('/api/articles', [
            'magazine_id' => $this->magazine->id,
            'title' => 'Submission with documents',
            'abstract' => 'Abstract',
            'terms_accepted' => true,
            'pdf_upload_id' => $this->cleanUpload('article_manuscript', 'manuscript.pdf')->id,
            'additional_manuscript_files' => [
                ['file_title' => 'Cover Letter', 'upload_id' => $cover->id],
                ['file_title' => 'Ethics Approval', 'upload_id' => $ethics->id],
            ],
        ])->assertStatus(211)->json('article.id');

        $files = ArticleFile::where('article_id', $articleId)
            ->where('file_type', ArticleFile::ADDITIONAL_MANUSCRIPT_FILE)
            ->get();
        $this->assertCount(2, $files);
        $this->assertEqualsCanonicalizing(['Cover Letter', 'Ethics Approval'], $files->pluck('file_title')->all());
        $this->assertTrue($files->every(fn (ArticleFile $file) => $file->article_version_id !== null));
    }

    public function test_later_resubmission_does_not_relink_previous_round_files(): void
    {
        $article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Revision history',
            'slug' => 'revision-history',
            'abstract' => 'Abstract',
            'full_text' => '',
            'status' => ArticleStatus::REVISION_REQUIRED,
        ]);
        $initialVersion = $article->versions()->create([
            'created_by' => $this->author->id,
            'version_number' => 1,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
            'metadata_snapshot' => [],
        ]);
        $initialFile = ArticleFile::create([
            'article_id' => $article->id,
            'article_version_id' => $initialVersion->id,
            'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::ADDITIONAL_MANUSCRIPT_FILE,
            'file_title' => 'Original Cover Letter',
            'visibility' => 'author_visible',
            'disk' => 's3',
            'file_path' => 'clean/original.pdf',
            'storage_key' => 'clean/original.pdf',
            'original_name' => 'original.pdf',
            'mime_type' => 'application/pdf',
            'size' => 14,
            'scan_status' => 'clean',
        ]);

        $r1 = $this->cleanUpload('additional_manuscript_file', 'r1.pdf', $article, 'R1 Declaration');
        $this->put('/api/admin/articles/'.$article->id, [
            'magazine_id' => $article->magazine_id,
            'title' => $article->title,
            'abstract' => $article->abstract,
            'pdf_upload_id' => $this->cleanUpload('article_revision', 'revised.pdf', $article)->id,
            'revision_response_upload_id' => $this->cleanUpload('article_revision_response', 'response.pdf', $article)->id,
            'additional_manuscript_files' => [['file_title' => 'R1 Declaration', 'upload_id' => $r1->id]],
        ])->assertOk();

        $this->assertSame($initialVersion->id, $initialFile->fresh()->article_version_id);
        $this->assertDatabaseHas('article_files', [
            'article_id' => $article->id,
            'file_title' => 'R1 Declaration',
            'file_type' => ArticleFile::ADDITIONAL_MANUSCRIPT_FILE,
        ]);
        $this->assertNotSame(
            $initialVersion->id,
            ArticleFile::where('file_title', 'R1 Declaration')->value('article_version_id')
        );

        $r1File = ArticleFile::where('file_title', 'R1 Declaration')->firstOrFail();
        $r1VersionId = $r1File->article_version_id;
        $article->refresh()->update(['status' => ArticleStatus::REVISION_REQUIRED]);
        $r2 = $this->cleanUpload('additional_manuscript_file', 'r2.pdf', $article, 'R2 Ethics Approval');
        $this->put('/api/admin/articles/'.$article->id, [
            'magazine_id' => $article->magazine_id,
            'title' => $article->title,
            'abstract' => $article->abstract,
            'pdf_upload_id' => $this->cleanUpload('article_revision', 'revised-r2.pdf', $article)->id,
            'revision_response_upload_id' => $this->cleanUpload('article_revision_response', 'response-r2.pdf', $article)->id,
            'additional_manuscript_files' => [['file_title' => 'R2 Ethics Approval', 'upload_id' => $r2->id]],
        ])->assertOk();

        $this->assertSame($initialVersion->id, $initialFile->fresh()->article_version_id);
        $this->assertSame($r1VersionId, $r1File->fresh()->article_version_id);
        $this->assertNotSame($r1VersionId, ArticleFile::where('file_title', 'R2 Ethics Approval')->value('article_version_id'));
        $this->assertSame(3, $article->versions()->count());
    }

    public function test_repeated_attachment_for_one_upload_session_is_idempotent(): void
    {
        $article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Idempotent attachment',
            'slug' => 'idempotent-attachment',
            'abstract' => 'Abstract',
            'full_text' => '',
            'status' => ArticleStatus::DRAFT,
        ]);
        $upload = $this->cleanUpload('additional_manuscript_file', 'cover.pdf', $article, 'Cover Letter');
        $controller = app(ArticleFileController::class);
        $config = config('media_uploads.purposes.additional_manuscript_file');

        $first = $controller->createCleanDirectUploadFile($article, $upload, $config);
        $second = $controller->createCleanDirectUploadFile($article, $upload, $config);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ArticleFile::where('media_upload_session_id', $upload->id)->count());
        $this->postJson("/api/media/uploads/{$upload->id}/complete")
            ->assertOk()
            ->assertJsonPath('upload.id', $upload->id)
            ->assertJsonPath('upload.status', MediaUploadSession::STATUS_CLEAN);
    }

    public function test_retry_cleanup_uses_a_fresh_session_without_duplicate_attachment(): void
    {
        $article = $this->draftArticle('retry-cleanup');
        $controller = app(ArticleFileController::class);
        $config = config('media_uploads.purposes.additional_manuscript_file');
        $failedUpload = $this->cleanUpload('additional_manuscript_file', 'failed.pdf', $article, 'Retry Document');
        $failedFile = $controller->createCleanDirectUploadFile($article, $failedUpload, $config);

        $this->deleteJson("/api/articles/{$article->id}/additional-manuscript-files/{$failedFile->id}")->assertOk();
        $retryUpload = $this->cleanUpload('additional_manuscript_file', 'retry.pdf', $article, 'Retry Document');
        $retryFile = $controller->createCleanDirectUploadFile($article, $retryUpload, $config);

        $this->assertNotSame($failedUpload->id, $retryUpload->id);
        $this->assertDatabaseMissing('article_files', ['id' => $failedFile->id]);
        $this->assertSame(1, ArticleFile::where('article_id', $article->id)->where('file_type', ArticleFile::ADDITIONAL_MANUSCRIPT_FILE)->count());
        $this->assertSame($retryUpload->id, $retryFile->media_upload_session_id);
    }

    public function test_historical_files_cannot_be_removed_and_payload_hides_storage_details(): void
    {
        $article = $this->draftArticle('historical-file');
        $version = $article->versions()->create([
            'created_by' => $this->author->id,
            'version_number' => 1,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
            'metadata_snapshot' => [],
        ]);
        $upload = $this->cleanUpload('additional_manuscript_file', 'private.pdf', $article, 'Private Cover Letter');
        $file = app(ArticleFileController::class)->createCleanDirectUploadFile($article, $upload, config('media_uploads.purposes.additional_manuscript_file'), [
            'article_version_id' => $version->id,
        ]);

        $this->deleteJson("/api/articles/{$article->id}/additional-manuscript-files/{$file->id}")->assertForbidden();
        $payload = app(ArticleFileController::class)->serializeFile($file);
        $this->assertArrayNotHasKey('disk', $payload);
        $this->assertArrayNotHasKey('file_path', $payload);
        $this->assertArrayNotHasKey('storage_key', $payload);
        $this->assertStringNotContainsString('X-Amz-', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_additional_file_access_obeys_author_editor_scope_and_reviewer_restriction(): void
    {
        $journal = Magazine::create([
            'title' => 'Documents Journal Two',
            'slug' => 'documents-journal-two',
            'publication_type' => Magazine::TYPE_JOURNAL,
        ]);
        $this->magazine->update(['publication_type' => Magazine::TYPE_MAGAZINE]);
        $magazineArticle = $this->draftArticle('magazine-access');
        $journalArticle = $this->draftArticle('journal-access', $journal);
        $magazineFile = $this->attachedFile($magazineArticle, 'magazine-private.pdf');
        $journalFile = $this->attachedFile($journalArticle, 'journal-private.pdf');

        $superAdmin = $this->userWithRole('super_admin');
        $superEditor = $this->userWithRole('super_editor');
        $magazineEditor = $this->userWithRole('magazine_editor');
        $journalEditor = $this->userWithRole('journal_editor');
        $unassignedEditor = $this->userWithRole('super_editor');
        $reviewer = $this->userWithRole('reviewer');
        $superEditor->magazines()->attach([$this->magazine->id => ['role' => 'editor'], $journal->id => ['role' => 'editor']]);
        $magazineEditor->magazines()->attach([$this->magazine->id => ['role' => 'editor'], $journal->id => ['role' => 'editor']]);
        $journalEditor->magazines()->attach([$this->magazine->id => ['role' => 'editor'], $journal->id => ['role' => 'editor']]);
        ReviewerAssignment::create([
            'article_id' => $magazineArticle->id,
            'reviewer_id' => $reviewer->id,
            'assigned_by' => $superAdmin->id,
            'status' => 'accepted',
        ]);

        $controller = app(ArticleFileController::class);
        $this->assertTrue($controller->canAccess($this->author, $magazineFile));
        $this->assertTrue($controller->canAccess($superAdmin, $magazineFile));
        $this->assertTrue($controller->canAccess($superEditor, $magazineFile));
        $this->assertTrue($controller->canAccess($superEditor, $journalFile));
        $this->assertTrue($controller->canAccess($magazineEditor, $magazineFile));
        $this->assertFalse($controller->canAccess($magazineEditor, $journalFile));
        $this->assertTrue($controller->canAccess($journalEditor, $journalFile));
        $this->assertFalse($controller->canAccess($journalEditor, $magazineFile));
        $this->assertFalse($controller->canAccess($unassignedEditor, $magazineFile));
        $this->assertFalse($controller->canAccess($reviewer, $magazineFile));

        Sanctum::actingAs($unassignedEditor);
        $this->getJson("/api/articles/files/{$magazineFile->id}/download")->assertForbidden();
        Sanctum::actingAs($reviewer);
        $this->getJson("/api/articles/files/{$magazineFile->id}/download")->assertForbidden();
    }

    private function draftArticle(string $slug, ?Magazine $magazine = null): Article
    {
        return Article::create([
            'magazine_id' => ($magazine ?? $this->magazine)->id,
            'user_id' => $this->author->id,
            'title' => 'Article '.$slug,
            'slug' => $slug,
            'abstract' => 'Abstract',
            'full_text' => '',
            'status' => ArticleStatus::DRAFT,
        ]);
    }

    private function attachedFile(Article $article, string $filename): ArticleFile
    {
        $upload = $this->cleanUpload('additional_manuscript_file', $filename, $article, 'Private document');

        return app(ArticleFileController::class)->createCleanDirectUploadFile($article, $upload, config('media_uploads.purposes.additional_manuscript_file'));
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => ucwords(str_replace('_', ' ', $roleName)), 'is_system' => true]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function cleanUpload(string $purpose, string $filename, ?Article $article = null, ?string $fileTitle = null): MediaUploadSession
    {
        $key = 'clean/test/'.$purpose.'/'.$filename;
        Storage::disk('s3')->put($key, "%PDF-1.4\n%%EOF");

        return MediaUploadSession::create([
            'user_id' => $this->author->id,
            'purpose' => $purpose,
            'attachable_type' => $article ? Article::class : null,
            'attachable_id' => $article?->id,
            'original_filename' => $filename,
            'safe_display_filename' => $filename,
            'expected_size_bytes' => 14,
            'declared_mime_type' => 'application/pdf',
            'disk' => 's3',
            's3_incoming_key' => 'incoming/'.$purpose.'/'.$filename,
            's3_clean_key' => $key,
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_CLEAN,
            'detected_mime_type' => 'application/pdf',
            'checksum_sha256' => str_repeat('c', 64),
            'scan_status' => 'clean',
            'metadata' => ['file_title' => $fileTitle],
            'expires_at' => now()->addHour(),
        ]);
    }
}
