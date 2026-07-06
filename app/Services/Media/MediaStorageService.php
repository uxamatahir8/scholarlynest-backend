<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

class MediaStorageService
{
    public function __construct(private readonly S3MediaKeyResolver $keys)
    {
    }

    public function disk(): string
    {
        return config('media_uploads.disk', 's3');
    }

    public function put(string $path, string $contents): string
    {
        $path = $this->keys->withPrefix($path);
        Storage::disk($this->disk())->put($path, $contents);

        return $path;
    }

    public function delete(?string $path): void
    {
        $resolved = $this->resolvePath($path);
        if (!$resolved) {
            return;
        }

        Storage::disk($resolved['disk'])->delete($resolved['path']);
    }

    public function exists(?string $path): bool
    {
        $resolved = $this->resolvePath($path);

        return $resolved ? Storage::disk($resolved['disk'])->exists($resolved['path']) : false;
    }

    public function temporaryUrl(?string $path, ?\DateTimeInterface $expiresAt = null): ?string
    {
        $resolved = $this->resolvePath($path);
        if (!$resolved) {
            return null;
        }

        $expiresAt ??= now()->addMinutes(config('media_uploads.download_url_ttl_minutes', 5));

        if ($resolved['disk'] === 'public') {
            return Storage::disk('public')->url($resolved['path']);
        }

        return Storage::disk($resolved['disk'])->temporaryUrl($resolved['path'], $expiresAt);
    }

    public function applicationUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        if (str_starts_with($path, '/images/') || str_starts_with($path, 'images/')) {
            return str_starts_with($path, '/') ? $path : '/' . $path;
        }

        $resolved = $this->resolvePath($path);
        if (!$resolved) {
            return null;
        }

        if ($resolved['disk'] === 'public') {
            return Storage::disk('public')->url($resolved['path']);
        }

        return url('/api/media/objects/' . $this->encodePath($path));
    }

    public function encodePath(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    public function decodePath(string $token): ?string
    {
        $padded = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    public function downloadResponse(?string $path, string $filename, string $contentType = 'application/octet-stream', string $disposition = 'attachment')
    {
        $resolved = $this->resolvePath($path);
        if (!$resolved || !Storage::disk($resolved['disk'])->exists($resolved['path'])) {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        if ($resolved['disk'] === 'public') {
            return response()->file(Storage::disk('public')->path($resolved['path']), [
                'Content-Type' => $contentType,
                'Content-Disposition' => $disposition . '; filename="' . addslashes($filename) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return redirect()->away(
            Storage::disk($resolved['disk'])->temporaryUrl($resolved['path'], now()->addMinutes(config('media_uploads.download_url_ttl_minutes', 5)), [
                'ResponseContentDisposition' => $disposition . '; filename="' . addslashes($filename) . '"',
                'ResponseContentType' => $contentType,
            ])
        );
    }

    public function resolvePath(?string $path): ?array
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return null;
        }

        if (str_starts_with($path, '/images/') || str_starts_with($path, 'images/')) {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            return ['disk' => 'public', 'path' => substr($path, strlen('storage/'))];
        }

        if (str_starts_with($path, '/storage/')) {
            return ['disk' => 'public', 'path' => substr($path, strlen('/storage/'))];
        }

        return ['disk' => $this->disk(), 'path' => $this->keys->withPrefix($path)];
    }

    public function publicOrTemporaryUrl(?string $path): ?string
    {
        return $this->applicationUrl($path);
    }
}
