<?php

namespace App\Services\Media;

use Illuminate\Support\Str;

class MediaContentInspector
{
    public function inspect(string $path, array $purposeConfig, string $originalFilename): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'reason' => 'file_unavailable'];
        }

        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > (int) $purposeConfig['max_size_bytes']) {
            return ['ok' => false, 'reason' => 'size_not_allowed'];
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $extension = Str::lower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (!in_array($detectedMime, $purposeConfig['detected_mime_types'] ?? [], true)) {
            return ['ok' => false, 'reason' => 'mime_not_allowed', 'mime' => $detectedMime];
        }

        if ($extension && !in_array($extension, $purposeConfig['extensions'] ?? [], true)) {
            return ['ok' => false, 'reason' => 'extension_not_allowed', 'mime' => $detectedMime];
        }

        $validationService = app(\App\Services\Media\UploadValidationService::class);

        if (!$validationService->extensionMatchesMime($extension, $detectedMime)) {
            return ['ok' => false, 'reason' => 'extension_mime_mismatch', 'mime' => $detectedMime];
        }

        if (!$validationService->validSignature($path, $detectedMime, $extension)) {
            return ['ok' => false, 'reason' => 'signature_mismatch', 'mime' => $detectedMime];
        }

        return [
            'ok' => true,
            'mime' => $detectedMime,
            'size' => $size,
            'checksum_sha256' => hash_file('sha256', $path),
        ];
    }
}
