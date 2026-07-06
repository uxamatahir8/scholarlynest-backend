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
use App\Services\Media\S3MediaKeyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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
            'media_uploads.s3_prefix' => 'dev',
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

    public function test_direct_upload_keys_are_server_generated_under_configured_prefix(): void
    {
        Sanctum::actingAs($this->author);

        $response = $this->postJson('/api/media/uploads/initiate', [
            'purpose' => 'article_supplementary',
            'attachable_id' => $this->article->id,
            'original_filename' => '../../chosen/prefix.pdf',
            'size_bytes' => 1024,
            'declared_mime_type' => 'application/pdf',
        ])->assertCreated();

        $session = MediaUploadSession::findOrFail($response->json('upload.id'));

        $this->assertStringStartsWith('dev/incoming/article_supplementary/', $session->s3_incoming_key);
        $this->assertStringNotContainsString('chosen/prefix', $session->s3_incoming_key);
        $this->assertArrayNotHasKey('s3_incoming_key', $response->json('upload'));
        $this->assertSame('application/pdf', $response->json('put.headers.Content-Type'));
        $this->assertArrayNotHasKey('x-amz-meta-upload-session-id', $response->json('put.headers'));
        $this->assertArrayNotHasKey('x-amz-meta-purpose', $response->json('put.headers'));
        $this->assertStringNotContainsString('x-amz-meta-upload-session-id', urldecode($response->json('put.url')));
        $this->assertStringNotContainsString('x-amz-meta-purpose', urldecode($response->json('put.url')));
    }

    public function test_active_upload_session_limit_returns_conflict_not_rate_limit(): void
    {
        config(['media_uploads.max_active_sessions_per_user' => 2]);
        Sanctum::actingAs($this->author);

        $this->uploadSession(['status' => MediaUploadSession::STATUS_UPLOADING]);
        $this->uploadSession(['status' => MediaUploadSession::STATUS_INITIATED]);

        $this->postJson('/api/media/uploads/initiate', $this->initiatePayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'active_upload_session_limit_reached')
            ->assertJsonPath('active_uploads', 2)
            ->assertJsonPath('limit', 2);
    }

    public function test_expired_aborted_and_scan_stage_sessions_do_not_block_initiation(): void
    {
        config(['media_uploads.max_active_sessions_per_user' => 1]);
        Sanctum::actingAs($this->author);

        $expired = $this->uploadSession([
            'status' => MediaUploadSession::STATUS_UPLOADING,
            'expires_at' => now()->subMinute(),
        ]);
        $this->uploadSession(['status' => MediaUploadSession::STATUS_ABORTED]);
        $this->uploadSession(['status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN]);
        $this->uploadSession(['status' => MediaUploadSession::STATUS_SCANNING]);

        $this->postJson('/api/media/uploads/initiate', $this->initiatePayload())
            ->assertCreated();

        $this->assertDatabaseHas('media_upload_sessions', [
            'id' => $expired->id,
            'status' => MediaUploadSession::STATUS_EXPIRED,
            'failure_reason' => 'upload_session_expired',
        ]);
    }

    public function test_one_initiate_request_creates_one_upload_session(): void
    {
        Sanctum::actingAs($this->author);

        $this->postJson('/api/media/uploads/initiate', $this->initiatePayload())
            ->assertCreated();

        $this->assertDatabaseCount('media_upload_sessions', 1);
    }

    public function test_actual_initiate_rate_limit_returns_structured_429(): void
    {
        RateLimiter::for('media-upload-initiate', function ($request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(1)
                ->by($request->user()->id . '|' . $request->ip());
        });
        Sanctum::actingAs($this->author);

        $this->postJson('/api/media/uploads/initiate', $this->initiatePayload([
            'file_fingerprint' => 'first.pdf:1024:1',
        ]))->assertCreated();

        $this->postJson('/api/media/uploads/initiate', $this->initiatePayload([
            'original_filename' => 'second.pdf',
            'file_fingerprint' => 'second.pdf:1024:2',
        ]))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertJsonPath('code', 'rate_limit_exceeded');
    }

    public function test_generic_raw_media_upload_endpoint_is_disabled(): void
    {
        Sanctum::actingAs($this->author);

        $this->post('/api/media', [
            'file' => UploadedFile::fake()->image('raw.jpg'),
        ])->assertGone()
            ->assertJsonPath('message', 'Raw browser uploads are disabled. Use the media upload-session direct S3 flow.');
    }

    public function test_raw_article_asset_upload_endpoint_is_disabled(): void
    {
        Sanctum::actingAs($this->author);

        $this->post("/api/articles/{$this->article->id}/assets", [
            'file' => UploadedFile::fake()->create('raw.pdf', 16, 'application/pdf'),
        ])->assertGone()
            ->assertJsonPath('message', 'Raw browser uploads are disabled for article assets. Use the direct S3 upload-session flow.');
    }

    public function test_clean_direct_upload_session_can_be_attached_as_article_file(): void
    {
        $session = $this->uploadSession();
        $session->forceFill([
            'status' => MediaUploadSession::STATUS_CLEAN,
            's3_clean_key' => 'dev/clean/articles/original/' . $session->id . '.pdf',
            'detected_mime_type' => 'application/pdf',
            'checksum_sha256' => str_repeat('a', 64),
            'scan_engine' => 'fake-clamav',
            'scanned_at' => now(),
        ])->save();

        $file = app(ArticleFileController::class)->createCleanDirectUploadFile($this->article, $session, [
            'article_file_type' => ArticleFile::MANUSCRIPT,
        ]);

        $this->assertSame('clean', $file->scan_status);
        $this->assertSame($session->s3_clean_key, $file->storage_key);
        $this->assertSame($session->id, $file->metadata['upload_session_id']);
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
        $this->assertStringStartsWith('dev/clean/articles/supplementary/', $session->fresh()->s3_clean_key);
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
        Storage::disk('s3')->assertExists(app(S3MediaKeyResolver::class)->quarantine($session));
    }

    private function initiatePayload(array $overrides = []): array
    {
        return array_merge([
            'purpose' => 'article_supplementary',
            'attachable_id' => $this->article->id,
            'original_filename' => 'supplement.pdf',
            'size_bytes' => 1024,
            'declared_mime_type' => 'application/pdf',
            'file_fingerprint' => 'supplement.pdf:1024:1',
        ], $overrides);
    }

    private function uploadSession(array $overrides = []): MediaUploadSession
    {
        return MediaUploadSession::create(array_merge([
            'user_id' => $this->author->id,
            'purpose' => 'article_supplementary',
            'attachable_type' => Article::class,
            'attachable_id' => $this->article->id,
            'original_filename' => 'supplement.pdf',
            'safe_display_filename' => 'supplement.pdf',
            'expected_size_bytes' => 37,
            'declared_mime_type' => 'application/pdf',
            'disk' => 's3',
            's3_incoming_key' => app(S3MediaKeyResolver::class)->incoming('article_supplementary', 'pdf'),
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
            'expires_at' => now()->addHour(),
        ], $overrides));
    }
}
