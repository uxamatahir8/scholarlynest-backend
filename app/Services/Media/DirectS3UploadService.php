<?php

namespace App\Services\Media;

use App\Models\MediaUploadSession;
use Aws\S3\S3Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class DirectS3UploadService
{
    public function __construct(private readonly S3MediaKeyResolver $keys)
    {
    }

    public function incomingKey(string $purpose, string $extension): string
    {
        return $this->keys->incoming($purpose, $extension);
    }

    public function cleanKey(MediaUploadSession $session, array $purposeConfig): string
    {
        return $this->keys->clean($session, $purposeConfig);
    }

    public function createMultipartUpload(MediaUploadSession $session): string
    {
        $result = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $session->s3_incoming_key,
            'ContentType' => $session->declared_mime_type ?: 'application/octet-stream',
            'ChecksumAlgorithm' => 'SHA256',
            'Metadata' => [
                'upload-session-id' => $session->id,
                'purpose' => $session->purpose,
            ],
        ]);

        return (string) $result['UploadId'];
    }

    public function signPut(MediaUploadSession $session): array
    {
        $command = $this->client()->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $session->s3_incoming_key,
            'ContentType' => $session->declared_mime_type ?: 'application/octet-stream',
        ]);

        $request = $this->client()->createPresignedRequest($command, $this->expiry());

        return [
            'url' => (string) $request->getUri(),
            'method' => 'PUT',
            'headers' => [
                'Content-Type' => $session->declared_mime_type ?: 'application/octet-stream',
            ],
            'expires_at' => now()->addMinutes(config('media_uploads.presign_ttl_minutes'))->toISOString(),
        ];
    }

    public function signParts(MediaUploadSession $session, array $partNumbers): array
    {
        return collect($partNumbers)
            ->map(function (int $partNumber) use ($session) {
                $command = $this->client()->getCommand('UploadPart', [
                    'Bucket' => $this->bucket(),
                    'Key' => $session->s3_incoming_key,
                    'UploadId' => $session->s3_upload_id,
                    'PartNumber' => $partNumber,
                    'ChecksumAlgorithm' => 'SHA256',
                ]);

                $request = $this->client()->createPresignedRequest($command, $this->expiry());

                return [
                    'part_number' => $partNumber,
                    'url' => (string) $request->getUri(),
                    'method' => 'PUT',
                    'headers' => [],
                ];
            })
            ->values()
            ->all();
    }

    public function listParts(MediaUploadSession $session): array
    {
        if (!$session->s3_upload_id) {
            return [];
        }

        $result = $this->client()->listParts([
            'Bucket' => $this->bucket(),
            'Key' => $session->s3_incoming_key,
            'UploadId' => $session->s3_upload_id,
        ]);

        return collect($result['Parts'] ?? [])->map(fn ($part) => [
            'part_number' => (int) $part['PartNumber'],
            'etag' => trim((string) $part['ETag'], '"'),
            'size' => (int) ($part['Size'] ?? 0),
            'checksum_sha256' => $part['ChecksumSHA256'] ?? null,
        ])->all();
    }

    public function completeMultipart(MediaUploadSession $session, array $parts): void
    {
        $s3Parts = collect($parts)
            ->sortBy('part_number')
            ->map(fn ($part) => [
                'PartNumber' => (int) $part['part_number'],
                'ETag' => Arr::get($part, 'etag'),
                'ChecksumSHA256' => Arr::get($part, 'checksum_sha256'),
            ])
            ->map(fn ($part) => array_filter($part, fn ($value) => $value !== null && $value !== ''))
            ->values()
            ->all();

        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $session->s3_incoming_key,
            'UploadId' => $session->s3_upload_id,
            'MultipartUpload' => ['Parts' => $s3Parts],
        ]);
    }

    public function abortMultipart(MediaUploadSession $session): void
    {
        if (!$session->s3_upload_id) {
            return;
        }

        $this->client()->abortMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $session->s3_incoming_key,
            'UploadId' => $session->s3_upload_id,
        ]);
    }

    public function head(MediaUploadSession $session): array
    {
        if (Storage::disk($session->disk)->exists($session->s3_incoming_key)) {
            return ['ContentLength' => Storage::disk($session->disk)->size($session->s3_incoming_key)];
        }

        $result = $this->client()->headObject([
            'Bucket' => $this->bucket(),
            'Key' => $session->s3_incoming_key,
        ]);

        return $result->toArray();
    }

    public function temporaryDownloadUrl(string $disk, string $key, string $filename): string
    {
        return Storage::disk($disk)->temporaryUrl($this->keys->withPrefix($key), now()->addMinutes(config('media_uploads.download_url_ttl_minutes')), [
            'ResponseContentDisposition' => 'attachment; filename="' . addslashes($filename) . '"',
        ]);
    }

    private function client(): S3Client
    {
        $s3 = config('filesystems.disks.' . config('media_uploads.disk'));

        return new S3Client([
            'version' => 'latest',
            'region' => $s3['region'] ?: 'ap-south-1',
            'endpoint' => $s3['endpoint'] ?: null,
            'use_path_style_endpoint' => (bool) ($s3['use_path_style_endpoint'] ?? false),
            'credentials' => [
                'key' => $s3['key'],
                'secret' => $s3['secret'],
            ],
        ]);
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.' . config('media_uploads.disk') . '.bucket');
    }

    private function expiry(): string
    {
        return '+' . config('media_uploads.presign_ttl_minutes') . ' minutes';
    }
}
