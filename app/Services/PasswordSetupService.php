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
            'Reset Your Scholarly Nest Password',
            'Dear ' . $user->name . ',',
            [
                'We received a request to reset the password for your Scholarly Nest account.',
                'Account: Email: ' . $user->email . '. Requested At: ' . now()->toDateTimeString() . '.',
                'Use the secure button below to reset your password. If you did not request this password reset, no action is required and your account will remain unchanged.',
                'Security Note: This link is temporary. Do not forward this email or share the reset link with anyone.',
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
            'Set Your Scholarly Nest Password',
            'Dear ' . $user->name . ',',
            [
                'An account has been created for you on Scholarly Nest.',
                'Account Details: Name: ' . $user->name . '. Email: ' . $user->email . '. Role: ' . ($user->role?->display_name ?? $user->role?->name ?? 'Not specified') . '. Created At: ' . now()->toDateTimeString() . '.',
                'To access your account, please set your password using the secure link below. This link is temporary and should only be used by you.',
                'Security Note: Scholarly Nest will never ask you to share your password by email. No password has been generated or sent in this message. If you did not expect this account, please ignore this email or contact support.',
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
