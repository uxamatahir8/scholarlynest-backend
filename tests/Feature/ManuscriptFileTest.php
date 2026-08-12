<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Http\Controllers\ArticleFileController;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\MediaUploadSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ArticleVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManuscriptFileTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private Magazine $magazine;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        foreach (['articles.create', 'articles.view-own', 'articles.edit-own'] as $name) {
            Permission::create(['name' => $name, 'module' => 'articles', 'description' => $name]);
        }
        $role->permissions()->sync(Permission::pluck('id'));
        $this->author = User::factory()->create(['role_id' => $role->id]);
        $this->magazine = Magazine::create(['title' => 'Manuscript Journal', 'slug' => 'manuscript-journal']);
        Storage::fake('s3');
    }

    public function test_draft_accepts_one_manuscript_and_rejects_a_distinct_second_upload(): void
    {
        $article = $this->article();
        $first = $this->attach($article, $this->upload($article, 'first.docx'));
        $this->assertNull($first->article_version_id);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);
        $this->attach($article, $this->upload($article, 'second.docx'));
    }

    public function test_same_upload_attachment_is_idempotent(): void
    {
        $article = $this->article();
        $upload = $this->upload($article, 'same.docx');
        $first = $this->attach($article, $upload);
        $second = $this->attach($article, $upload);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('article_files', 1);
    }

    public function test_snapshot_sets_authoritative_manuscript_and_rejects_unresolved_multiplicity(): void
    {
        $article = $this->article();
        $file = $this->attach($article, $this->upload($article, 'initial.docx'));
        $version = app(ArticleVersionService::class)->createSnapshot($article, $this->author, 'Initial Submission', null, null, [$file->id]);
        $this->assertSame($file->id, $version->manuscript_file_id);

        $corrupt = ArticleFile::create(array_merge($file->only([
            'article_id', 'article_version_id', 'uploaded_by', 'file_type', 'visibility', 'disk', 'mime_type', 'size', 'scan_status',
        ]), [
            'article_version_id' => $version->id, 'storage_key' => 'clean/distinct.docx', 'file_path' => 'clean/distinct.docx', 'original_name' => 'distinct.docx',
        ]));
        $version->update(['manuscript_file_id' => null]);
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);
        app(\App\Services\PrimaryManuscriptService::class)->authoritativeForSubmission($article, $version);
    }

    public function test_removal_clears_draft_file_and_session_and_replacement_is_fresh(): void
    {
        $article = $this->article();
        $oldUpload = $this->upload($article, 'old.docx');
        $oldFile = $this->attach($article, $oldUpload);
        Sanctum::actingAs($this->author);
        $this->deleteJson("/api/articles/{$article->id}/manuscript-files/{$oldFile->id}")->assertOk();
        $this->assertDatabaseMissing('article_files', ['id' => $oldFile->id]);
        $this->assertSame(MediaUploadSession::STATUS_ABORTED, $oldUpload->fresh()->status);
        $this->assertNull(data_get($oldUpload->fresh()->metadata, 'article_file_id'));

        $newUpload = $this->upload($article, 'new.docx');
        $newFile = $this->attach($article, $newUpload);
        $this->assertNotSame($oldUpload->id, $newUpload->id);
        $this->assertNotSame($oldUpload->client_upload_id, $newUpload->client_upload_id);
        $this->assertNotSame($oldFile->id, $newFile->id);
        $this->assertNotSame($oldFile->storage_key, $newFile->storage_key);
    }

    public function test_submitted_version_manuscript_cannot_be_removed(): void
    {
        $article = $this->article();
        $file = $this->attach($article, $this->upload($article, 'submitted.docx'));
        $version = app(ArticleVersionService::class)->createSnapshot($article, $this->author, 'Initial Submission', null, null, [$file->id]);
        $article->update(['status' => ArticleStatus::SUBMITTED]);
        Sanctum::actingAs($this->author);
        $this->deleteJson("/api/articles/{$article->id}/manuscript-files/{$file->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'This manuscript belongs to a submitted version and can no longer be removed.');
        $this->assertSame($file->id, $version->fresh()->manuscript_file_id);
    }

    public function test_initial_submission_without_manuscript_is_blocked(): void
    {
        Sanctum::actingAs($this->author);
        $this->postJson('/api/articles', [
            'magazine_id' => $this->magazine->id,
            'title' => 'Missing manuscript',
            'abstract' => 'Abstract',
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Upload a manuscript file before submitting this article.');
        $this->assertDatabaseCount('articles', 0);
    }

    private function article(): Article
    {
        return Article::create([
            'magazine_id' => $this->magazine->id, 'user_id' => $this->author->id, 'title' => 'Draft',
            'slug' => 'draft-'.Str::random(8), 'abstract' => 'Abstract', 'full_text' => '', 'status' => ArticleStatus::DRAFT,
        ]);
    }

    private function upload(Article $article, string $name): MediaUploadSession
    {
        $id = (string) Str::uuid();
        $key = "clean/manuscripts/{$id}/{$name}";
        Storage::disk('s3')->put($key, 'clean manuscript');
        return MediaUploadSession::create([
            'user_id' => $this->author->id, 'client_upload_id' => (string) Str::uuid(), 'purpose' => 'article_manuscript',
            'attachable_type' => Article::class, 'attachable_id' => $article->id, 'original_filename' => $name,
            'safe_display_filename' => $name, 'expected_size_bytes' => 16, 'declared_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'disk' => 's3', 's3_incoming_key' => "incoming/{$id}", 's3_clean_key' => $key, 'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_CLEAN, 'detected_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'checksum_sha256' => str_repeat('a', 64), 'scan_status' => 'clean', 'scanned_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

    private function attach(Article $article, MediaUploadSession $upload): ArticleFile
    {
        return app(ArticleFileController::class)->createCleanDirectUploadFile($article, $upload, config('media_uploads.purposes.article_manuscript'));
    }
}
