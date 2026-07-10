<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordSetupService
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function sendResetLink(User $user): string
    {
        $token = $this->createToken($user);
        $this->notificationService->send(
            $user->email,
            'Reset your ScholarlyNest password',
            'Reset your ScholarlyNest password',
            [
                'We received a request to reset your ScholarlyNest password.',
                'Use the secure button below to choose a new password. This link expires according to the configured reset-password policy.',
                'If you did not request this, you can safely ignore this email.',
            ],
            [
                'text' => 'Reset Password',
                'url' => $this->resetUrl($user->email, $token),
            ],
            'high',
            $user->id
        );

        return $token;
    }

    public function sendSetupLink(User $user): string
    {
        $token = $this->createToken($user);
        $this->notificationService->send(
            $user->email,
            'Set your ScholarlyNest password',
            'Your ScholarlyNest account has been created',
            [
                'Your account has been created by the ScholarlyNest team.',
                'Use the secure button below to set your password and access your account. This link expires according to the configured reset-password policy.',
                'If you were not expecting this account, please ignore this email.',
            ],
            [
                'text' => 'Set Password',
                'url' => $this->resetUrl($user->email, $token),
            ],
            'high',
            $user->id
        );

        return $token;
    }

    public function tokenIsValid(string $email, string $token): bool
    {
        $reset = DB::table('password_reset_tokens')
            ->where('email', strtolower($email))
            ->first();

        if (!$reset || !Hash::check($token, $reset->token)) {
            return false;
        }

        return !now()->subMinutes((int) config('auth.passwords.users.expire', 60))->gt($reset->created_at);
    }

    public function consumeToken(string $email): void
    {
        DB::table('password_reset_tokens')
            ->where('email', strtolower($email))
            ->delete();
    }

    private function createToken(User $user): string
    {
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => strtolower($user->email)],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        return $token;
    }

    private function resetUrl(string $email, string $token): string
    {
        return rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/')
            . '/reset-password?email=' . urlencode(strtolower($email))
            . '&token=' . urlencode($token);
    }
}
