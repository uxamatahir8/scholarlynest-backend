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

        if (!$this->validSignature($path, $detectedMime, $extension)) {
            return ['ok' => false, 'reason' => 'signature_mismatch', 'mime' => $detectedMime];
        }

        return [
            'ok' => true,
            'mime' => $detectedMime,
            'size' => $size,
            'checksum_sha256' => hash_file('sha256', $path),
        ];
    }

    private function validSignature(string $path, string $mime, string $extension): bool
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 560) ?: '';
        fclose($handle);

        if ($mime === 'application/pdf') {
            return str_starts_with($header, '%PDF-') || str_contains(substr($header, 0, 1024), '%PDF-');
        }

        if ($mime === 'image/png') {
            return str_starts_with($header, "\x89PNG\r\n\x1A\n");
        }

        if ($mime === 'image/jpeg') {
            return str_starts_with($header, "\xFF\xD8\xFF") && @getimagesize($path) !== false;
        }

        if ($mime === 'image/webp') {
            return str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP';
        }

        if (in_array($extension, ['docx', 'xlsx'], true)) {
            return str_starts_with($header, "PK\x03\x04");
        }

        if (in_array($extension, ['doc', 'xls'], true)) {
            return str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        }

        if (in_array($mime, ['text/plain', 'text/csv'], true)) {
            return !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $header);
        }

        return false;
    }
}
