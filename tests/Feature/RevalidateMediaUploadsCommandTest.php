<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\MediaUploadSession;
use App\Models\Role;
use App\Models\User;
use App\Services\Media\AntivirusScanResult;
use App\Services\Media\AntivirusScannerContract;
use App\Services\Media\S3MediaKeyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RevalidateMediaUploadsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private Article $article;
    private S3MediaKeyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media_uploads.disk' => 's3',
            'media_uploads.s3_prefix' => 'dev',
            'queue.default' => 'sync',
        ]);

        Storage::fake('s3');

        $role = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $this->author = User::factory()->create(['role_id' => $role->id]);

        $this->article = Article::create([
            'user_id' => $this->author->id,
            'title' => 'Test Article',
            'slug' => 'test-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => 'draft',
        ]);

        $this->resolver = app(S3MediaKeyResolver::class);

        // Bind a mock clean scanner
        $this->app->bind(AntivirusScannerContract::class, fn () => new class implements AntivirusScannerContract {
            public function scan(string $path): AntivirusScanResult
            {
                return new AntivirusScanResult('clean', 'fake-clamav');
            }
        });
    }

    public function test_revalidate_dry_run_does_not_modify_database_or_promote_file(): void
    {
        // 1. Create a dummy zip that acts as a valid OOXML package (using a size >= 100 bytes to trigger real zip checks)
        $tempZip = tempnam(sys_get_temp_dir(), 'test-docx-');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><document></document>');
        // Add dummy padding to exceed 100 bytes
        $zip->addFromString('padding.txt', str_repeat('a', 150));
        $zip->close();

        $session = MediaUploadSession::create([
            'user_id' => $this->author->id,
            'purpose' => 'article_manuscript',
            'original_filename' => 'manuscript.docx',
            'safe_display_filename' => 'manuscript.docx',
            'expected_size_bytes' => filesize($tempZip),
            'declared_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'disk' => 's3',
            's3_incoming_key' => 'dev/incoming/article_manuscript/manuscript.docx',
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_REJECTED,
            'failure_reason' => 'This file type is not supported.',
            'expires_at' => now()->addHour(),
        ]);

        $file = ArticleFile::create([
            'article_id' => $this->article->id,
            'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'original_name' => 'manuscript.docx',
            'disk' => 's3',
            'storage_key' => 'dev/incoming/article_manuscript/manuscript.docx',
            'file_path' => 'dev/incoming/article_manuscript/manuscript.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => filesize($tempZip),
            'scan_status' => 'rejected',
            'metadata' => ['upload_session_id' => $session->id],
        ]);

        $session->metadata = ['article_file_id' => $file->id];
        $session->save();

        // Put the valid docx ZIP content in quarantine
        $quarantineKey = $this->resolver->quarantine($session);
        Storage::disk('s3')->put($quarantineKey, file_get_contents($tempZip));
        @unlink($tempZip);

        // Run revalidate without --commit (dry-run)
        $this->artisan('media-uploads:revalidate', [
            'id' => $session->id,
        ])
        ->expectsOutputToContain('RUNNING IN DRY-RUN MODE')
        ->expectsOutputToContain('Would promote to S3 clean key')
        ->assertExitCode(0);

        // Assert database records remain rejected
        $this->assertSame(MediaUploadSession::STATUS_REJECTED, $session->fresh()->status);
        $this->assertSame('rejected', $file->fresh()->scan_status);
        Storage::disk('s3')->assertExists($quarantineKey);
        Storage::disk('s3')->assertMissing($session->fresh()->s3_clean_key);
    }

    public function test_revalidate_commit_promotes_valid_file_and_updates_database(): void
    {
        // 1. Create a valid docx ZIP
        $tempZip = tempnam(sys_get_temp_dir(), 'test-docx-');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><document></document>');
        $zip->addFromString('padding.txt', str_repeat('a', 150));
        $zip->close();

        $session = MediaUploadSession::create([
            'user_id' => $this->author->id,
            'purpose' => 'article_manuscript',
            'original_filename' => 'manuscript.docx',
            'safe_display_filename' => 'manuscript.docx',
            'expected_size_bytes' => filesize($tempZip),
            'declared_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'disk' => 's3',
            's3_incoming_key' => 'dev/incoming/article_manuscript/manuscript.docx',
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_REJECTED,
            'failure_reason' => 'This file type is not supported.',
            'expires_at' => now()->addHour(),
        ]);

        $file = ArticleFile::create([
            'article_id' => $this->article->id,
            'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'original_name' => 'manuscript.docx',
            'disk' => 's3',
            'storage_key' => 'dev/incoming/article_manuscript/manuscript.docx',
            'file_path' => 'dev/incoming/article_manuscript/manuscript.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => filesize($tempZip),
            'scan_status' => 'rejected',
            'metadata' => ['upload_session_id' => $session->id],
        ]);

        $session->metadata = ['article_file_id' => $file->id];
        $session->save();

        $quarantineKey = $this->resolver->quarantine($session);
        Storage::disk('s3')->put($quarantineKey, file_get_contents($tempZip));
        @unlink($tempZip);

        // Run with --commit
        $this->artisan('media-uploads:revalidate', [
            'id' => $session->id,
            '--commit' => true,
        ])
        ->expectsOutputToContain('RUNNING IN COMMIT MODE')
        ->expectsOutputToContain('Session promoted successfully.')
        ->assertExitCode(0);

        // Verify status and keys are updated
        $session = $session->fresh();
        $file = $file->fresh();

        $this->assertSame(MediaUploadSession::STATUS_CLEAN, $session->status);
        $this->assertNull($session->failure_reason);
        $this->assertSame('clean', $file->scan_status);
        $this->assertNotNull($session->s3_clean_key);
        $this->assertSame($session->s3_clean_key, $file->storage_key);

        // Quarantine key should be deleted
        Storage::disk('s3')->assertMissing($quarantineKey);
        Storage::disk('s3')->assertExists($session->s3_clean_key);
    }

    public function test_revalidate_rejects_and_keeps_invalid_ooxml_quarantined(): void
    {
        // ZIP that is missing word/document.xml (invalid docx)
        $tempZip = tempnam(sys_get_temp_dir(), 'test-docx-');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types></Types>');
        $zip->addFromString('padding.txt', str_repeat('a', 150));
        $zip->close();

        $session = MediaUploadSession::create([
            'user_id' => $this->author->id,
            'purpose' => 'article_manuscript',
            'original_filename' => 'invalid.docx',
            'safe_display_filename' => 'invalid.docx',
            'expected_size_bytes' => filesize($tempZip),
            'declared_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'disk' => 's3',
            's3_incoming_key' => 'dev/incoming/article_manuscript/invalid.docx',
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_REJECTED,
            'failure_reason' => 'This file type is not supported.',
            'expires_at' => now()->addHour(),
        ]);

        $quarantineKey = $this->resolver->quarantine($session);
        Storage::disk('s3')->put($quarantineKey, file_get_contents($tempZip));
        @unlink($tempZip);

        // Run revalidate with commit on invalid package
        $this->artisan('media-uploads:revalidate', [
            'id' => $session->id,
            '--commit' => true,
        ])
        ->expectsOutputToContain('Inspection failed: INVALID_OOXML_PACKAGE')
        ->assertExitCode(0);

        // Should still be rejected and quarantined
        $this->assertSame(MediaUploadSession::STATUS_REJECTED, $session->fresh()->status);
        Storage::disk('s3')->assertExists($quarantineKey);
    }

    public function test_revalidate_detects_path_traversal(): void
    {
        $tempZip = tempnam(sys_get_temp_dir(), 'test-docx-');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><document></document>');
        $zip->addFromString('../dangerous.txt', 'exploit');
        $zip->close();

        $session = MediaUploadSession::create([
            'user_id' => $this->author->id,
            'purpose' => 'article_manuscript',
            'original_filename' => 'traversal.docx',
            'safe_display_filename' => 'traversal.docx',
            'expected_size_bytes' => filesize($tempZip),
            'declared_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'disk' => 's3',
            's3_incoming_key' => 'dev/incoming/article_manuscript/traversal.docx',
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_REJECTED,
            'failure_reason' => 'This file type is not supported.',
            'expires_at' => now()->addHour(),
        ]);

        $quarantineKey = $this->resolver->quarantine($session);
        Storage::disk('s3')->put($quarantineKey, file_get_contents($tempZip));
        @unlink($tempZip);

        $this->artisan('media-uploads:revalidate', [
            'id' => $session->id,
            '--commit' => true,
        ])
        ->expectsOutputToContain('Inspection failed: PATH_TRAVERSAL_DETECTED')
        ->assertExitCode(0);
    }
}
