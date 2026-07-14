<?php

namespace App\Services;

use App\Models\MfaChallenge;
use App\Models\User;
use App\Models\UserMfaMethod;
use App\Models\UserMfaRecoveryCode;
use App\Models\UserMfaSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    public const MAX_ATTEMPTS = 15;

    public const MAX_METHOD_ATTEMPTS = 5;

    public const RECOVERY_UNLOCK_ATTEMPTS = 3;

    public const CHALLENGE_MINUTES = 10;

    public function __construct(private readonly Google2FA $totp) {}

    public function methods(User $user): array
    {
        $methods = $user->mfaMethods()->where('is_enabled', true)->where('is_verified', true)->pluck('method')->all();
        if ($user->two_factor_enabled && ! in_array('email', $methods, true)) {
            $methods[] = 'email';
        }

        return array_values(array_intersect(['email', 'totp'], $methods));
    }

    public function settings(User $user): array
    {
        $methods = $this->methods($user);
        $setting = $user->mfaSetting;
        $default = in_array($setting?->default_method, $methods, true)
            ? $setting->default_method
            : ($methods[0] ?? null);

        return [
            'is_enabled' => $methods !== [],
            'enabled_methods' => $methods,
            'default_method' => $default,
            'last_verified_at' => $setting?->last_verified_at,
            'recovery_codes_remaining' => $user->mfaRecoveryCodes()->whereNull('used_at')->count(),
        ];
    }

    public function defaultMethod(User $user, array $methods): string
    {
        $configured = $user->mfaSetting?->default_method;

        return in_array($configured, $methods, true) ? $configured : $methods[0];
    }

    /** @return array{token:string, challenge:MfaChallenge, email_code:?string, methods:array, default:string} */
    public function createChallenge(User $user, Request $request): array
    {
        $methods = $this->methods($user);
        if ($methods === []) {
            throw new \LogicException('Cannot create an MFA challenge without an enabled method.');
        }

        $default = $methods[0];
        $token = bin2hex(random_bytes(32));
        $emailCode = in_array('email', $methods, true) ? (string) random_int(100000, 999999) : null;
        $challenge = MfaChallenge::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'method_requested' => $default,
            'required_methods' => $methods,
            'verified_methods' => [],
            'email_code_hash' => $emailCode ? Hash::make($emailCode) : null,
            'email_code_sent_at' => $emailCode ? now() : null,
            'expires_at' => now()->addMinutes(self::CHALLENGE_MINUTES),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
        $this->logEvent('mfa.challenge.created', $challenge);

        return compact('token', 'challenge', 'emailCode', 'methods', 'default') + ['email_code' => $emailCode];
    }

    public function findUsableChallenge(string $token, bool $lock = false): MfaChallenge
    {
        $query = MfaChallenge::where('token_hash', hash('sha256', $token));
        if ($lock) {
            $query->lockForUpdate();
        }
        $challenge = $query->first();

        if (! $challenge || $challenge->consumed_at) {
            throw ValidationException::withMessages(['challenge_token' => ['This MFA challenge is invalid or has already been used.']]);
        }
        if ($challenge->expires_at->isPast()) {
            $this->logEvent('mfa.challenge.expired', $challenge);
            throw ValidationException::withMessages(['challenge_token' => ['This MFA challenge has expired. Please sign in again.']]);
        }
        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            $this->logEvent('mfa.challenge.failed', $challenge);
            throw ValidationException::withMessages(['challenge_token' => ['Too many failed attempts. Please sign in again.']]);
        }

        return $challenge;
    }

    public function verifyChallenge(string $token, string $method, string $code): array
    {
        $result = DB::transaction(function () use ($token, $method, $code) {
            $challenge = $this->findUsableChallenge($token, true);
            $user = User::findOrFail($challenge->user_id);
            $required = $challenge->required_methods ?: $this->methods($user);
            $verified = $challenge->verified_methods ?: [];
            $satisfiedMethod = $method === 'recovery_code' ? 'totp' : $method;

            if (! in_array($satisfiedMethod, $required, true) || in_array($satisfiedMethod, $verified, true)) {
                return ['complete' => false, 'error' => true, 'message' => 'That MFA method is not required for this challenge.', 'challenge' => $challenge];
            }
            if ($method === 'recovery_code' && ! $challenge->recovery_code_allowed) {
                return ['complete' => false, 'error' => true, 'message' => 'Recovery code verification is not available yet.', 'challenge' => $challenge];
            }
            $attemptField = match ($method) {
                'email' => 'email_attempt_count',
                'totp' => 'totp_attempt_count',
                'recovery_code' => 'recovery_code_attempt_count',
                default => null,
            };
            if ($attemptField && $challenge->{$attemptField} >= self::MAX_METHOD_ATTEMPTS) {
                return ['complete' => false, 'error' => true, 'message' => 'Too many failed attempts for this MFA method. Please sign in again.', 'challenge' => $challenge];
            }

            $valid = match ($method) {
                'email' => $challenge->email_code_hash && Hash::check($code, $challenge->email_code_hash),
                'totp' => $this->verifyTotpForUser($user, $code),
                'recovery_code' => $this->consumeRecoveryCode($user, $code),
                default => false,
            };
            if (! $valid) {
                $message = match ($method) {
                    'email' => 'Invalid email verification code.',
                    'totp' => 'Invalid authenticator code.',
                    'recovery_code' => 'Invalid recovery code.',
                    default => 'Invalid MFA code.',
                };

                return $this->failedVerification($challenge, $method, $message);
            }

            $verified[] = $satisfiedMethod;
            $verified = array_values(array_unique($verified));
            $remaining = array_values(array_diff($required, $verified));
            $complete = $remaining === [];
            $challenge->update([
                'verified_methods' => $verified,
                'method_requested' => $remaining[0] ?? $satisfiedMethod,
                'consumed_at' => $complete ? now() : null,
            ]);
            $this->logEvent($method === 'recovery_code' ? 'mfa.recovery_code.used' : "mfa.{$method}.verified", $challenge);

            if ($complete) {
                UserMfaSetting::updateOrCreate(['user_id' => $user->id], [
                    'is_enabled' => true,
                    'last_verified_at' => now(),
                ]);
                $this->logEvent('mfa.challenge.completed', $challenge);
            }

            return ['complete' => $complete, 'user' => $user, 'challenge' => $challenge->refresh()];
        });

        return $result;
    }

    public function challengeState(MfaChallenge $challenge): array
    {
        $required = $challenge->required_methods ?: $this->methods($challenge->user);
        $verified = $challenge->verified_methods ?: [];
        $remaining = array_values(array_diff($required, $verified));

        return [
            'mfa_required' => $remaining !== [],
            'required_methods' => array_values($required),
            'verified_methods' => array_values($verified),
            'remaining_methods' => $remaining,
            'next_method' => $remaining[0] ?? null,
            'recovery_code_allowed' => (bool) $challenge->recovery_code_allowed && in_array('totp', $remaining, true),
            'status' => $remaining === [] ? 'complete' : 'pending',
        ];
    }

    public function issueEmailCode(MfaChallenge $challenge): string
    {
        if (in_array('email', $challenge->verified_methods ?: [], true)) {
            throw ValidationException::withMessages(['method' => ['Email MFA is already verified for this challenge.']]);
        }
        if ($challenge->email_code_sent_at && $challenge->email_code_sent_at->gt(now()->subSeconds(60))) {
            throw ValidationException::withMessages(['challenge_token' => ['Please wait before requesting another email code.']]);
        }
        $code = (string) random_int(100000, 999999);
        $challenge->update([
            'method_requested' => 'email',
            'email_code_hash' => Hash::make($code),
            'email_code_sent_at' => now(),
        ]);

        return $code;
    }

    public function startTotpSetup(User $user): array
    {
        $method = UserMfaMethod::firstOrCreate(['user_id' => $user->id, 'method' => 'totp']);
        if ($method->is_enabled && $method->is_verified) {
            throw ValidationException::withMessages(['totp' => ['Authenticator App MFA is already enabled. Disable it before replacing the setup.']]);
        }
        $secret = $this->totp->generateSecretKey(32);
        $method->update([
            'pending_secret_encrypted' => Crypt::encryptString($secret),
            'pending_expires_at' => now()->addMinutes(15),
            'pending_attempts' => 0,
        ]);

        return [
            'otpauth_uri' => $this->totp->getQRCodeUrl('Scholarly Nest', $user->email, $secret),
            'manual_setup_key' => $secret,
            'expires_at' => $method->pending_expires_at,
        ];
    }

    public function verifyTotpSetup(User $user, string $code): array
    {
        $result = DB::transaction(function () use ($user, $code) {
            $method = UserMfaMethod::where('user_id', $user->id)->where('method', 'totp')->lockForUpdate()->first();
            if (! $method?->pending_secret_encrypted || ! $method->pending_expires_at || $method->pending_expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => ['The authenticator setup has expired. Start setup again.']]);
            }
            if ($method->pending_attempts >= self::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['code' => ['Too many failed setup attempts. Start setup again.']]);
            }
            $secret = Crypt::decryptString($method->pending_secret_encrypted);
            $timestamp = $this->totp->verifyKey($secret, $code, 1);
            if ($timestamp === false) {
                $method->increment('pending_attempts');

                return null;
            }
            $method->update([
                'is_enabled' => true,
                'is_verified' => true,
                'secret_encrypted' => Crypt::encryptString($secret),
                'pending_secret_encrypted' => null,
                'pending_expires_at' => null,
                'pending_attempts' => 0,
                'metadata_json' => ['last_totp_timestamp' => $timestamp],
            ]);
            $setting = UserMfaSetting::firstOrCreate(['user_id' => $user->id]);
            $setting->update([
                'is_enabled' => true,
                'default_method' => $setting->default_method ?: 'totp',
                'last_verified_at' => now(),
            ]);

            return $this->regenerateRecoveryCodes($user);
        });
        if ($result === null) {
            throw ValidationException::withMessages(['code' => ['The authenticator code is invalid.']]);
        }

        return $result;
    }

    public function verifyTotpForUser(User $user, string $code): bool
    {
        $method = UserMfaMethod::where('user_id', $user->id)->where('method', 'totp')
            ->where('is_enabled', true)->where('is_verified', true)->lockForUpdate()->first();
        if (! $method?->secret_encrypted) {
            return false;
        }
        $oldTimestamp = $method->metadata_json['last_totp_timestamp'] ?? null;
        $timestamp = $this->totp->verifyKeyNewer(Crypt::decryptString($method->secret_encrypted), $code, $oldTimestamp, 1);
        if ($timestamp === false) {
            return false;
        }
        $metadata = $method->metadata_json ?? [];
        $metadata['last_totp_timestamp'] = $timestamp;
        $method->update(['metadata_json' => $metadata, 'last_used_at' => now()]);

        return true;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        $user->mfaRecoveryCodes()->delete();
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $code = substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 4);
            UserMfaRecoveryCode::create(['user_id' => $user->id, 'code_hash' => Hash::make($this->normalizeRecoveryCode($code))]);
            $codes[] = $code;
        }

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        foreach ($user->mfaRecoveryCodes()->whereNull('used_at')->lockForUpdate()->get() as $recoveryCode) {
            if (Hash::check($this->normalizeRecoveryCode($code), $recoveryCode->code_hash)) {
                $recoveryCode->update(['used_at' => now()]);

                return true;
            }
        }

        return false;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
    }

    private function failedVerification(MfaChallenge $challenge, string $method, string $message): array
    {
        $counter = match ($method) {
            'email' => 'email_attempt_count',
            'totp' => 'totp_attempt_count',
            'recovery_code' => 'recovery_code_attempt_count',
            default => null,
        };
        $nextCount = $counter ? ((int) $challenge->{$counter}) + 1 : 0;
        $updates = ['attempts' => ((int) $challenge->attempts) + 1];
        if ($counter) {
            $updates[$counter] = $nextCount;
        }
        if ($method === 'totp' && $nextCount >= self::RECOVERY_UNLOCK_ATTEMPTS && ! $challenge->recovery_code_allowed) {
            $updates['recovery_code_allowed'] = true;
            $message = 'Invalid authenticator code. You may use a recovery code if you cannot access your authenticator app.';
        }
        $challenge->update($updates);
        $this->logEvent($method === 'totp' ? 'mfa.totp.failed' : 'mfa.challenge.failed', $challenge);
        if (($updates['recovery_code_allowed'] ?? false) === true) {
            $this->logEvent('mfa.recovery_code.unlocked', $challenge);
        }
        if ($counter && $nextCount >= self::MAX_METHOD_ATTEMPTS) {
            $message = 'Too many failed attempts for this MFA method. Please sign in again.';
        }

        return ['complete' => false, 'error' => true, 'message' => $message, 'challenge' => $challenge->refresh()];
    }

    private function logEvent(string $event, MfaChallenge $challenge): void
    {
        Log::notice($event, [
            'challenge_id' => $challenge->id,
            'user_id' => $challenge->user_id,
            'ip_address' => $challenge->ip_address,
        ]);
    }
}
