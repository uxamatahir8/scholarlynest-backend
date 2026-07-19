<?php

namespace App\Services\Media;

use Illuminate\Support\Str;

class UploadValidationService
{
    /**
     * Get the exact list of allowed extensions.
     */
    public static function getAllowedExtensions(): array
    {
        return [
            'pdf', 'doc', 'docx', 'rtf', 'odt', 'txt',
            'xls', 'xlsx', 'csv', 'ods',
            'ppt', 'pptx', 'odp',
            'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'bmp', 'gif', 'svg',
            'zip'
        ];
    }

    /**
     * Get the exact list of allowed MIME types, including variations.
     */
    public static function getAllowedMimeTypes(): array
    {
        return [
            'application/pdf', 'application/x-pdf', 'application/acrobat', 'applications/vnd.pdf', 'text/pdf',
            'application/msword', 'application/vnd.ms-office',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/rtf', 'text/rtf', 'text/richtext', 'application/x-rtf',
            'text/plain',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-ms-excel', 'application/x-excel', 'application/x-dos_ms_excel', 'application/xls', 'application/x-xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv', 'application/csv', 'text/comma-separated-values',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.ms-powerpoint', 'application/powerpoint', 'application/mspowerpoint', 'application/x-mspowerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
            'image/jpeg', 'image/pjpeg', 'image/jpg',
            'image/png', 'image/x-png',
            'image/webp',
            'image/tiff', 'image/x-tiff',
            'image/bmp', 'image/x-ms-bmp', 'image/x-bmp',
            'image/gif',
            'image/svg+xml', 'image/svg',
            'application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip',
            'application/octet-stream'
        ];
    }

    /**
     * Get the exact validation error message string.
     */
    public static function getErrorMessage(): string
    {
        return "This file type is not supported.\n\nAllowed formats:\nPDF, DOC, DOCX, RTF, ODT, TXT,\nXLS, XLSX, CSV,\nPPT, PPTX,\nJPG, PNG, WEBP, TIFF,\nZIP.";
    }

    /**
     * Get the Laravel validation rules string for 'mimes'.
     */
    public static function extensionsRuleString(): string
    {
        return implode(',', self::getAllowedExtensions());
    }

    /**
     * Check if a purpose is part of the academic workflow submission process.
     */
    public static function isWorkflowPurpose(string $purpose): bool
    {
        return in_array($purpose, [
            'article_manuscript',
            'article_revision',
            'article_revision_response',
            'additional_manuscript_file',
            'article_supplementary',
            'article_production_file',
            'article_plagiarism_report',
            'article_proof_file',
            'article_published_pdf',
            'article_annotated_manuscript',
            'article_reviewed_manuscript',
        ], true);
    }

    /**
     * Check if the extension is compatible with the detected MIME type.
     */
    public function extensionMatchesMime(string $extension, string $mime): bool
    {
        if ($extension === '') {
            return true;
        }

        $extension = Str::lower($extension);
        $mime = Str::lower($mime);

        $allowed = [
            'pdf' => ['application/pdf', 'application/x-pdf', 'application/acrobat', 'applications/vnd.pdf', 'text/pdf'],
            'png' => ['image/png', 'image/x-png'],
            'jpg' => ['image/jpeg', 'image/pjpeg', 'image/jpg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg', 'image/jpg'],
            'webp' => ['image/webp'],
            'gif' => ['image/gif'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp'],
            'tif' => ['image/tiff', 'image/x-tiff'],
            'tiff' => ['image/tiff', 'image/x-tiff'],
            'svg' => ['image/svg+xml', 'image/svg'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'application/csv', 'text/comma-separated-values', 'application/vnd.ms-excel', 'text/plain'],
            'doc' => ['application/msword', 'application/octet-stream', 'application/vnd.ms-office'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-ms-excel', 'application/x-excel', 'application/x-dos_ms_excel', 'application/xls', 'application/x-xls', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/powerpoint', 'application/mspowerpoint', 'application/x-mspowerpoint', 'application/octet-stream'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
            'rtf' => ['application/rtf', 'text/rtf', 'text/richtext', 'application/x-rtf', 'text/plain'],
            'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip', 'application/octet-stream'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip', 'application/octet-stream'],
            'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip', 'application/octet-stream'],
            'zip' => ['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip', 'application/octet-stream'],
        ];

        return !isset($allowed[$extension]) || in_array($mime, $allowed[$extension], true);
    }

    /**
     * Check if the file signature/magic header matches the format.
     */
    public function validSignature(string $path, string $mime, string $extension): bool
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 1024) ?: '';
        fclose($handle);

        $extension = Str::lower($extension);
        $mime = Str::lower($mime);

        // PDF
        if ($extension === 'pdf' || str_contains($mime, 'pdf')) {
            return str_starts_with($header, '%PDF-') || str_contains(substr($header, 0, 1024), '%PDF-');
        }

        // PNG
        if ($extension === 'png' || $mime === 'image/png' || $mime === 'image/x-png') {
            return str_starts_with($header, "\x89PNG\r\n\x1A\n");
        }

        // JPEG/JPG
        if (in_array($extension, ['jpg', 'jpeg'], true) || in_array($mime, ['image/jpeg', 'image/pjpeg'], true)) {
            return str_starts_with($header, "\xFF\xD8\xFF") && @getimagesize($path) !== false;
        }

        // WEBP
        if ($extension === 'webp' || $mime === 'image/webp') {
            return str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP';
        }

        // GIF
        if ($extension === 'gif' || $mime === 'image/gif') {
            return str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a');
        }

        // BMP
        if ($extension === 'bmp' || in_array($mime, ['image/bmp', 'image/x-ms-bmp'], true)) {
            return str_starts_with($header, 'BM');
        }

        // TIFF
        if (in_array($extension, ['tif', 'tiff'], true) || in_array($mime, ['image/tiff', 'image/x-tiff'], true)) {
            return str_starts_with($header, "II\x2A\x00") || str_starts_with($header, "MM\x00\x2A");
        }

        // SVG
        if ($extension === 'svg' || $mime === 'image/svg+xml' || $mime === 'image/svg') {
            return (str_contains($header, '<svg') || str_contains($header, 'svg') || str_contains(strtolower($header), '<svg'))
                && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $header);
        }

        // OOXML / Zip-based (docx, xlsx, pptx, zip, odt, ods, odp)
        if (in_array($extension, ['docx', 'xlsx', 'pptx', 'zip', 'odt', 'ods', 'odp'], true)) {
            return str_starts_with($header, "PK\x03\x04");
        }

        // OLE / CFB (doc, xls, ppt)
        if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            return str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        }

        // RTF
        if ($extension === 'rtf' || in_array($mime, ['application/rtf', 'text/rtf', 'text/richtext', 'application/x-rtf'], true)) {
            return str_starts_with($header, '{\rtf');
        }

        // Text / CSV
        if (in_array($extension, ['txt', 'csv'], true) || in_array($mime, ['text/plain', 'text/csv'], true)) {
            return !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $header);
        }

        return false;
    }
}
