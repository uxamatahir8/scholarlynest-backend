<?php

namespace App\Services\Media;

use App\Http\Controllers\ArticleFileController;
use App\Models\Article;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaUploadPolicy
{
    public function configForPurpose(string $purpose): array
    {
        $config = config("media_uploads.purposes.$purpose");
        if (!$config) {
            throw ValidationException::withMessages(['purpose' => 'This upload purpose is not allowed.']);
        }

        return $config;
    }

    public function resolveAttachable(string $purpose, ?int $targetId): ?Model
    {
        $config = $this->configForPurpose($purpose);
        if (($config['target'] ?? null) === 'article') {
            if (!$targetId) {
                throw ValidationException::withMessages(['attachable_id' => 'An article target is required.']);
            }

            return Article::findOrFail($targetId);
        }

        return null;
    }

    public function authorizeInitiate(Authenticatable $user, string $purpose, ?Model $attachable, array $metadata = []): void
    {
        $config = $this->configForPurpose($purpose);

        if (($config['target'] ?? null) === 'article') {
            $article = $attachable;
            $fileType = $config['article_file_type'];
            $allowed = app(ArticleFileController::class)->canUploadForDirectSession(
                $user,
                $article,
                $fileType,
                $metadata['assignment_type'] ?? null,
                isset($metadata['assignment_id']) ? (int) $metadata['assignment_id'] : null
            );

            if (!$allowed) {
                abort(403, 'This action is unauthorized.');
            }
        }
    }

    public function sanitizeFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $basename = preg_replace('/[^\w.\- ()]/', '_', $basename) ?: 'upload';
        $basename = trim($basename, " .\t\n\r\0\x0B");

        return Str::limit($basename ?: 'upload', 180, '');
    }
}
