<?php

namespace App\Http\Controllers;

use App\Models\UserMfaMethod;
use App\Models\UserMfaSetting;
use App\Services\MfaService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly NotificationService $notifications,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['mfa' => $this->mfa->settings($request->user())]);
    }

    public function startTotpSetup(Request $request): JsonResponse
    {
        return response()->json($this->mfa->startTotpSetup($request->user()));
    }

    public function verifyTotpSetup(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $codes = $this->mfa->verifyTotpSetup($request->user(), $validated['code']);

        return response()->json([
            'message' => 'Authenticator App MFA has been enabled.',
            'recovery_codes' => $codes,
            'mfa' => $this->mfa->settings($request->user()->refresh()),
        ]);
    }

    public function disableTotp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
        ]);
        $this->confirmSensitiveAction($request, $validated);
        $user = $request->user();
        UserMfaMethod::where('user_id', $user->id)->where('method', 'totp')->update([
            'is_enabled' => false,
            'is_verified' => false,
            'secret_encrypted' => null,
            'pending_secret_encrypted' => null,
            'pending_expires_at' => null,
            'pending_attempts' => 0,
            'metadata_json' => null,
        ]);
        $setting = UserMfaSetting::firstOrCreate(['user_id' => $user->id]);
        $methods = $this->mfa->methods($user->refresh());
        $setting->update([
            'is_enabled' => $methods !== [],
            'default_method' => in_array('email', $methods, true) ? 'email' : null,
        ]);
        $user->mfaRecoveryCodes()->delete();

        return response()->json(['message' => 'Authenticator App MFA has been disabled.', 'mfa' => $this->mfa->settings($user)]);
    }

    public function setDefault(Request $request): JsonResponse
    {
        $validated = $request->validate(['method' => ['required', Rule::in(['email', 'totp'])]]);
        $user = $request->user();
        if (! in_array($validated['method'], $this->mfa->methods($user), true)) {
            throw ValidationException::withMessages(['method' => ['The default method must be enabled and verified.']]);
        }
        UserMfaSetting::updateOrCreate(['user_id' => $user->id], [
            'is_enabled' => true,
            'default_method' => $validated['method'],
        ]);

        return response()->json(['message' => 'Default MFA method updated.', 'mfa' => $this->mfa->settings($user->refresh())]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
        ]);
        $this->confirmSensitiveAction($request, $validated);
        if (! in_array('totp', $this->mfa->methods($request->user()), true)) {
            throw ValidationException::withMessages(['totp' => ['Authenticator App MFA must be enabled.']]);
        }

        return response()->json([
            'message' => 'New recovery codes generated. Previous codes are no longer valid.',
            'recovery_codes' => $this->mfa->regenerateRecoveryCodes($request->user()),
        ]);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'method' => ['required', Rule::in(['email', 'totp', 'recovery_code'])],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $user = $this->mfa->verifyChallenge($validated['challenge_token'], $validated['method'], $validated['code']);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => app(AuthController::class)->userPayload($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function resendEmail(Request $request): JsonResponse
    {
        $validated = $request->validate(['challenge_token' => ['required', 'string', 'size:64']]);
        $challenge = $this->mfa->findUsableChallenge($validated['challenge_token']);
        $user = $challenge->user;
        if (! in_array('email', $this->mfa->methods($user), true)) {
            throw ValidationException::withMessages(['method' => ['Email MFA is not enabled for this account.']]);
        }
        $code = $this->mfa->issueEmailCode($challenge);
        $this->sendEmailCode($user->email, $user->id, $code);

        return response()->json(['message' => 'A new authentication code was sent to your email.']);
    }

    private function confirmSensitiveAction(Request $request, array $validated): void
    {
        $user = $request->user();
        $passwordValid = ! empty($validated['current_password']) && $user->password
            && Hash::check($validated['current_password'], $user->password);
        $totpValid = ! empty($validated['code']) && $this->mfa->verifyTotpForUser($user, $validated['code']);
        if (! $passwordValid && ! $totpValid) {
            throw ValidationException::withMessages(['confirmation' => ['Enter your current password or a valid authenticator code.']]);
        }
    }

    private function sendEmailCode(string $email, int $userId, string $code): void
    {
        $this->notifications->sendSensitive($email, 'Your Two-Factor Authentication Code', 'Two-Factor Authentication', [
            'A sign-in attempt was detected for your Scholarly Nest profile. Use the 6-digit verification code below to authorize your session.',
            '<div class="code-box"><div class="code-value">'.htmlspecialchars($code).'</div></div>',
            '<div style="font-size: 12px; color: #a1a1aa; text-align: center; margin-top: 16px;">This code is sensitive and expires in 10 minutes.</div>',
        ], null, 'high', $userId);
    }
}
