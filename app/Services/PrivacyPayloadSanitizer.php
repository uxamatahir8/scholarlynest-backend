<?php

namespace App\Services;

class PrivacyPayloadSanitizer
{
    private const SENSITIVE_KEYS = ['email', 'avatar', 'profile_url', 'storage_key', 'file_path', 'invite_token', 'invite_token_hash', 'password', 'setup_token'];

    public function sanitize(array $payload, bool $hideIdentity = false): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                unset($payload[$key]);

                continue;
            }
            if ($hideIdentity && in_array(strtolower((string) $key), ['name', 'institution', 'affiliation', 'user_id', 'reviewer_id'], true)) {
                unset($payload[$key]);

                continue;
            }
            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value, $hideIdentity);
            }
        }

        return $payload;
    }
}
