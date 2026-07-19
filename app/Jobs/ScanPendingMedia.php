<?php

namespace App\Jobs;

use App\Models\ArticleAsset;
use App\Models\ArticleFile;
use App\Models\Media;
use App\Models\MediaUploadSession;
use App\Services\Media\AntivirusScannerContract;
use App\Services\Media\DirectS3UploadService;
use App\Services\Media\MediaContentInspector;
use App\Services\Media\S3MediaKeyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScanPendingMedia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public string $uploadSessionId)
    {
        $this->onQueue(config('media_uploads.queues.scan'));
    }

    public function handle(
        MediaContentInspector $inspector,
        AntivirusScannerContract $scanner,
        DirectS3UploadService $s3,
    ): void {
        $session = MediaUploadSession::query()->lockForUpdate()->find($this->uploadSessionId);
        if (!$session || !in_array($session->status, [MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN, MediaUploadSession::STATUS_SCAN_FAILED], true)) {
            return;
        }

        $purposeConfig = config("media_uploads.purposes.{$session->purpose}");
        if (!$purposeConfig) {
            $this->reject($session, 'purpose_not_configured');
            return;
        }

        $session->forceFill(['status' => MediaUploadSession::STATUS_SCANNING])->save();
        $tempPath = tempnam(storage_path('app/private'), 'media-scan-');

        try {
            $stream = Storage::disk($session->disk)->readStream($session->s3_incoming_key);
            if (!$stream) {
                throw new \RuntimeException('incoming_object_unavailable');
            }

            $target = fopen($tempPath, 'wb');
            stream_copy_to_stream($stream, $target);
            fclose($target);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $inspection = $inspector->inspect($tempPath, $purposeConfig, $session->safe_display_filename, $session->id);
            if (!($inspection['ok'] ?? false)) {
                $reason = $inspection['reason'] ?? 'content_validation_failed';
                $failureCode = $inspection['failure_code'] ?? null;

                if (\App\Services\Media\UploadValidationService::isWorkflowPurpose($session->purpose)) {
                    if ($failureCode) {
                        $structuredError = \App\Services\Media\UploadValidationService::getStructuredError($failureCode, $session->safe_display_filename);
                        $reason = $structuredError['message'];
                    } elseif (in_array($reason, ['mime_not_allowed', 'extension_not_allowed', 'extension_mime_mismatch', 'signature_mismatch'], true)) {
                        $reason = \App\Services\Media\UploadValidationService::getErrorMessage();
                    }
                }
                $this->reject($session, $reason, $inspection);
                return;
            }

            if (
                $session->expected_checksum_sha256
                && !hash_equals(strtolower($session->expected_checksum_sha256), strtolower($inspection['checksum_sha256']))
            ) {
                $this->reject($session, 'checksum_mismatch', $inspection);
                return;
            }

            $scan = $scanner->scan($tempPath);
            if ($scan->status !== 'clean') {
                if ($scan->status === 'scan_failed' && $this->attempts() < $this->tries) {
                    $session->forceFill([
                        'status' => MediaUploadSession::STATUS_SCAN_FAILED,
                        'scan_status' => 'scan_failed',
                        'scan_engine' => $scan->engine,
                        'failure_reason' => $scan->safeReason ?: 'scanner_unavailable',
                    ])->save();

                    throw new \RuntimeException('media_scan_failed');
                }

                $reason = $scan->safeReason ?: $scan->status;
                if (\App\Services\Media\UploadValidationService::isWorkflowPurpose($session->purpose)) {
                    $structuredError = \App\Services\Media\UploadValidationService::getStructuredError('MALWARE_DETECTED', $session->safe_display_filename);
                    $reason = $structuredError['message'];
                }
                $this->reject($session, $reason, $inspection, $scan->engine, $scan->status);
                return;
            }

            $cleanKey = $s3->cleanKey($session, $purposeConfig);
            $cleanSource = Storage::disk($session->disk)->readStream($session->s3_incoming_key);
            if (!$cleanSource || !Storage::disk($session->disk)->writeStream($cleanKey, $cleanSource)) {
                if (is_resource($cleanSource)) {
                    fclose($cleanSource);
                }

                throw new \RuntimeException('clean_copy_failed');
            }
            if (is_resource($cleanSource)) {
                fclose($cleanSource);
            }
            if (!Storage::disk($session->disk)->exists($cleanKey)) {
                throw new \RuntimeException('clean_copy_verification_failed');
            }

            DB::transaction(function () use ($session, $cleanKey, $inspection, $scan) {
                $session->refresh();
                $session->forceFill([
                    'status' => MediaUploadSession::STATUS_CLEAN,
                    's3_clean_key' => $cleanKey,
                    'detected_mime_type' => $inspection['mime'],
                    'checksum_sha256' => $inspection['checksum_sha256'],
                    'scan_engine' => $scan->engine,
                    'scan_status' => 'clean',
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
                    'scan_engine' => $scan->engine,
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

            Storage::disk($session->disk)->delete($session->s3_incoming_key);
        } catch (\Throwable $e) {
            $session->forceFill([
                'status' => MediaUploadSession::STATUS_SCAN_FAILED,
                'scan_status' => 'scan_failed',
                'failure_reason' => 'scan_pipeline_failed',
            ])->save();

            Log::warning('Media scan failed closed.', [
                'upload_session_id' => $session->id,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($tempPath && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function reject(MediaUploadSession $session, string $reason, array $inspection = [], ?string $engine = null, string $scanStatus = 'rejected'): void
    {
        $session->forceFill([
            'status' => MediaUploadSession::STATUS_REJECTED,
            'detected_mime_type' => $inspection['mime'] ?? null,
            'checksum_sha256' => $inspection['checksum_sha256'] ?? null,
            'scan_status' => $scanStatus,
            'scan_engine' => $engine,
            'scanned_at' => now(),
            'failure_reason' => $reason,
        ])->save();

        $metadata = $session->metadata ?: [];
        if (!empty($metadata['article_file_id'])) {
            ArticleFile::whereKey($metadata['article_file_id'])->update(['scan_status' => 'rejected']);
        }
        if (!empty($metadata['article_asset_id'])) {
            ArticleAsset::whereKey($metadata['article_asset_id'])->update(['scan_status' => 'rejected']);
        }
        if (!empty($metadata['media_id'])) {
            Media::whereKey($metadata['media_id'])->update(['scan_status' => 'rejected']);
        }

        $quarantineKey = app(S3MediaKeyResolver::class)->quarantine($session);
        if (Storage::disk($session->disk)->exists($session->s3_incoming_key)) {
            $quarantineSource = Storage::disk($session->disk)->readStream($session->s3_incoming_key);
            if ($quarantineSource) {
                Storage::disk($session->disk)->writeStream($quarantineKey, $quarantineSource);
                if (is_resource($quarantineSource)) {
                    fclose($quarantineSource);
                }
            }
            Storage::disk($session->disk)->delete($session->s3_incoming_key);
        }
    }
}
