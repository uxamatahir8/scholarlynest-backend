<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MediaContentInspector
{
    public function inspect(string $path, array $purposeConfig, string $originalFilename, ?string $uploadSessionId = null): array
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
            $this->logInspectionFailure('mime_not_allowed', $uploadSessionId, $originalFilename, $detectedMime, $extension, $purposeConfig);
            return ['ok' => false, 'reason' => 'mime_not_allowed', 'mime' => $detectedMime, 'failure_code' => 'MIME_NOT_ALLOWED'];
        }

        if ($extension && !in_array($extension, $purposeConfig['extensions'] ?? [], true)) {
            $this->logInspectionFailure('extension_not_allowed', $uploadSessionId, $originalFilename, $detectedMime, $extension, $purposeConfig);
            return ['ok' => false, 'reason' => 'extension_not_allowed', 'mime' => $detectedMime, 'failure_code' => 'EXTENSION_NOT_ALLOWED'];
        }

        $validationService = app(UploadValidationService::class);

        if (!$validationService->extensionMatchesMime($extension, $detectedMime)) {
            $this->logInspectionFailure('extension_mime_mismatch', $uploadSessionId, $originalFilename, $detectedMime, $extension, $purposeConfig);
            return ['ok' => false, 'reason' => 'extension_mime_mismatch', 'mime' => $detectedMime, 'failure_code' => 'EXTENSION_MIME_MISMATCH'];
        }

        if (!$validationService->validSignature($path, $detectedMime, $extension)) {
            $this->logInspectionFailure('signature_mismatch', $uploadSessionId, $originalFilename, $detectedMime, $extension, $purposeConfig);
            return ['ok' => false, 'reason' => 'signature_mismatch', 'mime' => $detectedMime, 'failure_code' => 'SIGNATURE_MISMATCH'];
        }

        // OOXML/ODF package validation for ZIP-based document formats
        if (
            UploadValidationService::requiresOoxmlValidation($extension)
            && in_array($detectedMime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true)
        ) {
            $ooxmlResult = $validationService->validateOoxmlPackage($path, $extension);
            if (!$ooxmlResult['valid']) {
                Log::warning('Media content inspector: OOXML validation failed.', [
                    'upload_session_id' => $uploadSessionId,
                    'filename' => $originalFilename,
                    'extension' => $extension,
                    'detected_mime' => $detectedMime,
                    'ooxml_code' => $ooxmlResult['code'],
                    'ooxml_message' => $ooxmlResult['message'],
                ]);
                return [
                    'ok' => false,
                    'reason' => $ooxmlResult['code'],
                    'mime' => $detectedMime,
                    'failure_code' => $ooxmlResult['code'],
                    'failure_detail' => $ooxmlResult['message'],
                ];
            }
        }

        return [
            'ok' => true,
            'mime' => $detectedMime,
            'size' => $size,
            'checksum_sha256' => hash_file('sha256', $path),
        ];
    }

    private function logInspectionFailure(
        string $reason,
        ?string $uploadSessionId,
        string $filename,
        string $detectedMime,
        string $extension,
        array $purposeConfig,
    ): void {
        Log::warning('Media content inspector: validation failed.', [
            'upload_session_id' => $uploadSessionId,
            'reason' => $reason,
            'filename' => $filename,
            'detected_mime' => $detectedMime,
            'extension' => $extension,
            'purpose' => $purposeConfig['purpose'] ?? null,
        ]);
    }
}
