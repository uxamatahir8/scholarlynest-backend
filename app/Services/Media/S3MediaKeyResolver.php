<?php

namespace App\Services\Media;

use App\Models\MediaUploadSession;
use Illuminate\Support\Str;

class S3MediaKeyResolver
{
    public function prefix(): string
    {
        return trim((string) config('media_uploads.s3_prefix', ''), '/');
    }

    public function incoming(string $purpose, string $extension = ''): string
    {
        $suffix = $extension !== '' ? '.' . ltrim($extension, '.') : '';

        return $this->withPrefix(
            'incoming/' . trim($purpose, '/') . '/' . now()->format('Y/m/d') . '/' . (string) Str::uuid() . $suffix
        );
    }

    public function clean(MediaUploadSession $session, array $purposeConfig): string
    {
        $extension = pathinfo($session->safe_display_filename, PATHINFO_EXTENSION);
        $suffix = $extension ? ".{$extension}" : '';

        return $this->withPrefix(rtrim((string) $purposeConfig['clean_prefix'], '/') . '/' . $session->id . $suffix);
    }

    public function legacy(string $type, int|string $id, string $basename): string
    {
        return $this->withPrefix('incoming/legacy/' . trim($type, '/') . '/' . $id . '/' . basename($basename));
    }

    public function quarantine(MediaUploadSession $session): string
    {
        return $this->withPrefix('quarantine/' . $session->id);
    }

    public function temporary(string $name): string
    {
        return $this->withPrefix('temporary/' . ltrim($name, '/'));
    }

    public function withPrefix(?string $key): ?string
    {
        if (!$key) {
            return $key;
        }

        $normalized = ltrim(preg_replace('#/+#', '/', str_replace('\\', '/', $key)), '/');
        $prefix = $this->prefix();

        if ($prefix === '' || $normalized === $prefix || str_starts_with($normalized, $prefix . '/')) {
            return $normalized;
        }

        return $prefix . '/' . $normalized;
    }

    public function stripPrefix(?string $key): ?string
    {
        if (!$key) {
            return $key;
        }

        $normalized = ltrim(preg_replace('#/+#', '/', str_replace('\\', '/', $key)), '/');
        $prefix = $this->prefix();

        if ($prefix !== '' && str_starts_with($normalized, $prefix . '/')) {
            return substr($normalized, strlen($prefix) + 1);
        }

        return $normalized;
    }
}
