<?php

namespace Tests;

use App\Models\Article;
use App\Models\MediaUploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    protected function cleanManuscriptUpload(User $user, ?Article $article = null, string $purpose = 'article_manuscript'): MediaUploadSession
    {
        $id = (string) Str::uuid();
        $key = "testing/clean/{$purpose}/{$id}.pdf";
        Storage::disk('s3')->put($key, "%PDF-1.4\n%%EOF");

        return MediaUploadSession::create([
            'user_id' => $user->id,
            'client_upload_id' => (string) Str::uuid(),
            'purpose' => $purpose,
            'attachable_type' => $article ? Article::class : null,
            'attachable_id' => $article?->id,
            'original_filename' => 'manuscript.pdf',
            'safe_display_filename' => 'manuscript.pdf',
            'expected_size_bytes' => 14,
            'declared_mime_type' => 'application/pdf',
            'disk' => 's3',
            's3_incoming_key' => "testing/incoming/{$id}.pdf",
            's3_clean_key' => $key,
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_CLEAN,
            'detected_mime_type' => 'application/pdf',
            'checksum_sha256' => str_repeat('a', 64),
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }
}
