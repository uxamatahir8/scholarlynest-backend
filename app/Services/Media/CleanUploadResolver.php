<?php

namespace App\Services\Media;

use App\Models\MediaUploadSession;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CleanUploadResolver
{
    public function resolveOwned(User $user, ?string $uploadId, string|array $purpose): ?MediaUploadSession
    {
        if (!$uploadId) {
            return null;
        }

        $purposes = (array) $purpose;
        $session = MediaUploadSession::query()
            ->whereKey($uploadId)
            ->where('user_id', $user->id)
            ->whereIn('purpose', $purposes)
            ->first();

        if (!$session) {
            throw ValidationException::withMessages([
                'upload_id' => 'The selected upload session is not available.',
            ]);
        }

        if ($session->status !== MediaUploadSession::STATUS_CLEAN || !$session->s3_clean_key) {
            throw ValidationException::withMessages([
                'upload_id' => 'The selected upload has not passed security scanning.',
            ]);
        }

        if (!Storage::disk($session->disk)->exists($session->s3_clean_key)) {
            $session->forceFill([
                'status' => MediaUploadSession::STATUS_SCAN_FAILED,
                'failure_reason' => 'clean_object_missing',
            ])->save();
            throw ValidationException::withMessages([
                'upload_id' => 'The uploaded file could not be verified in storage. Please retry.',
            ]);
        }

        if ((int) Storage::disk($session->disk)->size($session->s3_clean_key) !== (int) $session->expected_size_bytes) {
            $session->forceFill([
                'status' => MediaUploadSession::STATUS_SCAN_FAILED,
                'failure_reason' => 'clean_object_size_mismatch',
            ])->save();
            throw ValidationException::withMessages([
                'upload_id' => 'The uploaded file could not be verified in storage. Please retry.',
            ]);
        }

        return $session;
    }

    public function cleanKey(User $user, ?string $uploadId, string|array $purpose): ?string
    {
        return $this->resolveOwned($user, $uploadId, $purpose)?->s3_clean_key;
    }
}
