<?php

namespace App\Console\Commands;

use App\Models\ArticleAsset;
use App\Models\ArticleFile;
use App\Models\Media;
use App\Models\MediaUploadSession;
use App\Services\Media\AntivirusScannerContract;
use App\Services\Media\DirectS3UploadService;
use App\Services\Media\MediaContentInspector;
use App\Services\Media\S3MediaKeyResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RevalidateMediaUploadsCommand extends Command
{
    protected $signature = 'media-uploads:revalidate
                            {id? : The specific MediaUploadSession ID to revalidate}
                            {--article-file= : Filter by metadata.article_file_id}
                            {--rejected-type-only : Only process sessions rejected due to unsupported type}
                            {--commit : Actually apply changes and promote files (default is dry-run)}';

    protected $description = 'Revalidate quarantined media uploads and promote them if they pass validation and scanning';

    public function handle(
        MediaContentInspector $inspector,
        AntivirusScannerContract $scanner,
        DirectS3UploadService $s3,
        S3MediaKeyResolver $resolver
    ): int {
        $commit = $this->option('commit');
        $this->info($commit ? 'RUNNING IN COMMIT MODE' : 'RUNNING IN DRY-RUN MODE (pass --commit to apply changes)');

        $query = MediaUploadSession::query()->where(function ($q) {
            $q->where('status', MediaUploadSession::STATUS_REJECTED)
              ->orWhere(function ($q2) {
                  $q2->where('status', MediaUploadSession::STATUS_ABORTED)
                     ->where('scan_status', 'rejected');
              });
        });

        if ($id = $this->argument('id')) {
            $query->where('id', $id);
        }

        if ($articleFileId = $this->option('article-file')) {
            $query->where('metadata->article_file_id', $articleFileId);
        }

        if ($this->option('rejected-type-only')) {
            $query->where(function ($q) {
                $q->where('failure_reason', 'like', '%type is not supported%')
                  ->orWhere('failure_reason', 'mime_not_allowed')
                  ->orWhere('failure_reason', 'extension_not_allowed')
                  ->orWhere('failure_reason', 'extension_mime_mismatch')
                  ->orWhere('failure_reason', 'signature_mismatch');
            });
        }

        $sessions = $query->get();

        if ($sessions->isEmpty()) {
            $this->info('No matching rejected upload sessions found.');
            return 0;
        }

        $this->info("Found {$sessions->count()} session(s) to process.");

        foreach ($sessions as $session) {
            $this->line("--------------------------------------------------");
            $this->info("Processing Session: {$session->id}");
            $this->line("  Filename: {$session->original_filename}");
            $this->line("  Purpose: {$session->purpose}");
            $this->line("  Declared MIME: {$session->declared_mime_type}");
            $this->line("  Original failure reason: {$session->failure_reason}");

            $quarantineKey = $resolver->quarantine($session);
            if (!Storage::disk($session->disk)->exists($quarantineKey)) {
                $this->error("  Quarantine file missing on S3: {$quarantineKey}");
                continue;
            }

            $purposeConfig = config("media_uploads.purposes.{$session->purpose}");
            if (!$purposeConfig) {
                $this->error("  Purpose not configured: {$session->purpose}");
                continue;
            }

            $tempPath = tempnam(storage_path('app/private'), 'media-revalidate-');
            try {
                $stream = Storage::disk($session->disk)->readStream($quarantineKey);
                if (!$stream) {
                    $this->error("  Could not read stream from S3 for: {$quarantineKey}");
                    continue;
                }

                $target = fopen($tempPath, 'wb');
                stream_copy_to_stream($stream, $target);
                fclose($target);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                // Inspect
                $inspection = $inspector->inspect($tempPath, $purposeConfig, $session->safe_display_filename, $session->id);
                if (!($inspection['ok'] ?? false)) {
                    $reason = $inspection['reason'] ?? 'content_validation_failed';
                    $this->error("  Inspection failed: {$reason}");
                    continue;
                }

                // Antivirus Scan
                $scanResult = $scanner->scan($tempPath);
                if ($scanResult->status !== 'clean') {
                    $this->error("  Antivirus scan failed/not clean: status={$scanResult->status}, reason={$scanResult->safeReason}");
                    continue;
                }

                $this->info("  [PASS] Inspection and Antivirus scan succeeded.");

                $cleanKey = $s3->cleanKey($session, $purposeConfig);

                if (!$commit) {
                    $this->comment("  [DRY-RUN] Would promote to S3 clean key: {$cleanKey}");
                    continue;
                }

                // Commit
                $this->info("  Promoting file to S3 clean key: {$cleanKey}");
                $cleanSource = Storage::disk($session->disk)->readStream($quarantineKey);
                if (!$cleanSource || !Storage::disk($session->disk)->writeStream($cleanKey, $cleanSource)) {
                    if (is_resource($cleanSource)) {
                        fclose($cleanSource);
                    }
                    throw new \RuntimeException('Failed to write stream to clean key.');
                }
                if (is_resource($cleanSource)) {
                    fclose($cleanSource);
                }

                DB::transaction(function () use ($session, $cleanKey, $inspection, $scanResult) {
                    $session->forceFill([
                        'status' => MediaUploadSession::STATUS_CLEAN,
                        's3_clean_key' => $cleanKey,
                        'detected_mime_type' => $inspection['mime'],
                        'checksum_sha256' => $inspection['checksum_sha256'],
                        'scan_status' => 'clean',
                        'scan_engine' => $scanResult->engine,
                        'scanned_at' => now(),
                        'failure_reason' => null,
                    ])->save();

                    $metadata = $session->metadata ?: [];
                    $updates = [
                        'disk' => $session->disk,
                        'storage_key' => $cleanKey,
                        'file_path' => $cleanKey,
                        'mime_type' => $inspection['mime'],
                        'checksum_sha256' => $inspection['checksum_sha256'],
                        'scan_status' => 'clean',
                        'scan_engine' => $scanResult->engine,
                        'scanned_at' => now(),
                    ];

                    if (!empty($metadata['article_file_id'])) {
                        ArticleFile::whereKey($metadata['article_file_id'])->update($updates);
                        $file = ArticleFile::find($metadata['article_file_id']);
                        if ($file && $file->file_type === ArticleFile::MANUSCRIPT) {
                            $file->article?->update(['pdf_path' => $cleanKey]);
                        }
                    }

                    if (!empty($metadata['article_asset_id'])) {
                        ArticleAsset::whereKey($metadata['article_asset_id'])->update(array_merge($updates, [
                            'file_path' => $cleanKey,
                        ]));
                    }

                    if (!empty($metadata['media_id'])) {
                        Media::whereKey($metadata['media_id'])->update(array_merge(\Illuminate\Support\Arr::except($updates, ['file_path']), [
                            'url' => app(\App\Services\Media\MediaStorageService::class)->publicOrTemporaryUrl($cleanKey) ?? '',
                        ]));
                    }
                });

                Storage::disk($session->disk)->delete($quarantineKey);
                $this->info("  [SUCCESS] Session promoted successfully.");

            } catch (\Throwable $e) {
                $this->error("  Exception while processing: {$e->getMessage()}");
            } finally {
                if ($tempPath && is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }

        return 0;
    }
}
