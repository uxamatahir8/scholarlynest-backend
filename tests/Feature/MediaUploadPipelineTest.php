<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Http\Controllers\ArticleFileController;
use App\Jobs\ScanPendingMedia;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MediaUploadSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Media\AntivirusScanResult;
use App\Services\Media\AntivirusScannerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaUploadPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private User $otherUser;
    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media_uploads.disk' => 's3',
            'queue.default' => 'sync',
        ]);

        Storage::fake('s3');
        Storage::fake('public');

        $role = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        foreach (['articles.view-own', 'articles.edit-own', 'articles.manage-assets'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['module' => 'articles', 'description' => $permission]);
        }
        $role->permissions()->sync(Permission::pluck('id'));

        $this->author = User::factory()->create(['role_id' => $role->id]);
        $this->otherUser = User::factory()->create(['role_id' => $role->id]);
        $magazine = Magazine::create(['title' => 'Security Journal', 'slug' => 'security-journal']);
        $this->article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Secure Uploads',
            'slug' => 'secure-uploads',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::DRAFT,
        ]);
    }

    public function test_unauthenticated_user_cannot_initiate_upload(): void
    {
        $this->postJson('/api/media/uploads/initiate', [])->assertUnauthorized();
    }

    public function test_client_cannot_use_unknown_purpose_or_oversized_file(): void
    {
        Sanctum::actingAs($this->author);

        $this->postJson('/api/media/uploads/initiate', [
            'purpose' => 'arbitrary_shell',
            'attachable_id' => $this->article->id,
            'original_filename' => 'shell.php',
            'size_bytes' => 100,
        ])->assertUnprocessable();

        $this->postJson('/api/media/uploads/initiate', [
            'purpose' => 'article_supplementary',
            'attachable_id' => $this->article->id,
            'original_filename' => 'large.pdf',
            'size_bytes' => 50 * 1024 * 1024,
        ])->assertStatus(422);
    }

    public function test_pending_scan_file_cannot_be_downloaded(): void
    {
        $file = app(ArticleFileController::class)->createPendingDirectUploadFile($this->article, $this->uploadSession(), [
            'article_file_type' => ArticleFile::SUPPLEMENTARY,
        ]);

        Sanctum::actingAs($this->author);

        $this->getJson("/api/articles/files/{$file->id}/download")->assertNotFound();
    }

    public function test_scan_job_promotes_clean_pdf_and_marks_record_available(): void
    {
        $this->app->bind(AntivirusScannerContract::class, fn () => new class implements AntivirusScannerContract {
            public function scan(string $path): AntivirusScanResult
            {
                return new AntivirusScanResult('clean', 'fake-clamav');
            }
        });

        $session = $this->uploadSession();
        Storage::disk('s3')->put($session->s3_incoming_key, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $file = app(ArticleFileController::class)->createPendingDirectUploadFile($this->article, $session, [
            'article_file_type' => ArticleFile::SUPPLEMENTARY,
            'clean_prefix' => 'clean/articles/supplementary/',
        ]);
        $session->metadata = ['article_file_id' => $file->id];
        $session->save();

        app(ScanPendingMedia::class, ['uploadSessionId' => $session->id])->handle(
            app(\App\Services\Media\MediaContentInspector::class),
            app(AntivirusScannerContract::class),
            app(\App\Services\Media\DirectS3UploadService::class),
        );

        $this->assertDatabaseHas('media_upload_sessions', ['id' => $session->id, 'status' => 'clean']);
        $this->assertDatabaseHas('article_files', ['id' => $file->id, 'scan_status' => 'clean']);
        Storage::disk('s3')->assertMissing($session->s3_incoming_key);
    }

    public function test_scanner_failure_fails_closed(): void
    {
        $this->app->bind(AntivirusScannerContract::class, fn () => new class implements AntivirusScannerContract {
            public function scan(string $path): AntivirusScanResult
            {
                return new AntivirusScanResult('infected', 'fake-clamav', 'infected');
            }
        });

        $session = $this->uploadSession();
        Storage::disk('s3')->put($session->s3_incoming_key, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $file = app(ArticleFileController::class)->createPendingDirectUploadFile($this->article, $session, [
            'article_file_type' => ArticleFile::SUPPLEMENTARY,
            'clean_prefix' => 'clean/articles/supplementary/',
        ]);
        $session->metadata = ['article_file_id' => $file->id];
        $session->save();

        app(ScanPendingMedia::class, ['uploadSessionId' => $session->id])->handle(
            app(\App\Services\Media\MediaContentInspector::class),
            app(AntivirusScannerContract::class),
            app(\App\Services\Media\DirectS3UploadService::class),
        );

        $this->assertDatabaseHas('media_upload_sessions', ['id' => $session->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('article_files', ['id' => $file->id, 'scan_status' => 'rejected']);
    }

    private function uploadSession(): MediaUploadSession
    {
        return MediaUploadSession::create([
            'user_id' => $this->author->id,
            'purpose' => 'article_supplementary',
            'attachable_type' => Article::class,
            'attachable_id' => $this->article->id,
            'original_filename' => 'supplement.pdf',
            'safe_display_filename' => 'supplement.pdf',
            'expected_size_bytes' => 37,
            'declared_mime_type' => 'application/pdf',
            'disk' => 's3',
            's3_incoming_key' => 'incoming/article_supplementary/test.pdf',
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
            'expires_at' => now()->addHour(),
        ]);
    }
}
