<?php

namespace App\Http\Controllers;

use App\Models\NewsletterSubscriber;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\CleanUploadResolver;
use App\Services\Media\MediaStorageService;
use App\Services\MfaService;
use App\Services\NotificationService;
use App\Services\PasswordSetupService;
use Google\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected NotificationService $notificationService;

    protected PasswordSetupService $passwordSetupService;

    protected MfaService $mfaService;

    public function __construct(NotificationService $notificationService, PasswordSetupService $passwordSetupService, MfaService $mfaService)
    {
        $this->notificationService = $notificationService;
        $this->passwordSetupService = $passwordSetupService;
        $this->mfaService = $mfaService;
    }

    /**
     * Generate and dispatch a beautiful HTML verification email.
     */
    private function sendHtmlEmail(string $email, string $subject, string $title, string $description, string $code, ?array $action = null): void
    {
        $user = User::where('email', $email)->first();
        $userId = $user ? $user->id : null;

        $bodyLines = [
            $description,
            '<div class="code-box"><div class="code-value">'.htmlspecialchars($code).'</div></div>',
            '<div style="font-size: 12px; color: #a1a1aa; text-align: center; margin-top: 16px;">This code is highly sensitive and is valid for the next 15 minutes. Do not share this code with anyone.</div>',
        ];

        $this->notificationService->sendSensitive(
            $email,
            $subject,
            $title,
            $bodyLines,
            $action,
            'high',
            $userId,
        );
    }

    /**
     * Handle public user registration.
     */
    public function register(Request $request): JsonResponse
    {
        if ($this->hasAuthenticatedBearer($request)) {
            return response()->json(['message' => 'Authenticated users cannot access registration.'], 403);
        }

        if (! $this->publicRegistrationEnabled()) {
            return response()->json(['message' => 'Registration is currently closed.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'university_name' => 'required|string|max:255',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $defaultRole = $this->defaultRegistrationRole();
        $code = strval(random_int(100000, 999999));

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'university_name' => $request->university_name,
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(15),
            'role_id' => $defaultRole?->id,
        ]);

        if ($request->boolean('subscribe_newsletter')) {
            NewsletterSubscriber::firstOrCreate([
                'email' => strtolower(trim($user->email)),
            ]);
        }

        $this->sendHtmlEmail(
            $user->email,
            'Verify Your Scholar Account',
            'Verify Your Account',
            'Thank you for registering at ScholarlyNest. Please use the 6-digit confirmation code below to verify your email address.',
            $code
        );

        return response()->json([
            'message' => 'verification_required',
            'email' => $user->email,
        ], 201);
    }

    /**
     * Handle user verification.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified.'], 400);
        }

        if ($user->verification_code !== $request->code) {
            return response()->json(['message' => 'Invalid verification code.'], 400);
        }

        if (now()->gt($user->verification_code_expires_at)) {
            return response()->json(['message' => 'Verification code has expired.'], 400);
        }

        // Mark as verified
        $user->update([
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_code_expires_at' => null,
        ]);

        $this->sendWelcomeHtmlEmail($user->email, $user->name, null);

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => $this->authUserPayload($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Resend verification code.
     */
    public function resendVerificationCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified.'], 400);
        }

        $code = strval(random_int(100000, 999999));
        $user->update([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->sendHtmlEmail(
            $user->email,
            'Verify Your Scholar Account',
            'Verify Your Account',
            'Thank you for registering at ScholarlyNest. Please use the 6-digit confirmation code below to verify your email address.',
            $code
        );

        return response()->json([
            'message' => 'Verification code resent successfully.',
        ]);
    }

    /**
     * Handle user login.
     */
    public function login(Request $request): JsonResponse
    {
        if ($this->hasAuthenticatedBearer($request)) {
            return response()->json(['message' => 'Authenticated users cannot access login.'], 403);
        }

        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // If email is not verified, return verification required status
        if (! $user->email_verified_at) {
            if (! $user->verification_code || now()->gt($user->verification_code_expires_at)) {
                $code = strval(random_int(100000, 999999));
                $user->update([
                    'verification_code' => $code,
                    'verification_code_expires_at' => now()->addMinutes(15),
                ]);
                $this->sendHtmlEmail(
                    $user->email,
                    'Verify Your Scholar Account',
                    'Verify Your Account',
                    'Thank you for registering at ScholarlyNest. Please use the 6-digit confirmation code below to verify your email address.',
                    $code
                );
            }

            return response()->json([
                'message' => 'verification_required',
                'email' => $user->email,
            ], 403);
        }

        if ($this->mfaService->methods($user) !== []) {
            return $this->mfaChallengeResponse($user, $request);
        }

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $this->authUserPayload($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Handle user 2FA verification.
     */
    public function verify2Fa(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->two_factor_code !== $request->code) {
            return response()->json(['message' => 'Invalid 2FA code.'], 400);
        }

        if (now()->gt($user->two_factor_code_expires_at)) {
            return response()->json(['message' => '2FA code has expired.'], 400);
        }

        // Clear code on success
        $user->update([
            'two_factor_code' => null,
            'two_factor_code_expires_at' => null,
        ]);

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $this->authUserPayload($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Enable 2FA setting.
     */
    public function enable2Fa(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->two_factor_enabled = true;
        $user->save();
        $user->mfaMethods()->updateOrCreate(['method' => 'email'], ['is_enabled' => true, 'is_verified' => true]);
        $setting = $user->mfaSetting()->firstOrCreate();
        $setting->update(['is_enabled' => true, 'default_method' => $setting->default_method ?: 'email']);

        return response()->json([
            'message' => 'Two-Factor Authentication has been enabled successfully.',
            'two_factor_enabled' => true,
        ]);
    }

    /**
     * Request 2FA disable code.
     */
    public function request2FaDisableCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = strval(random_int(100000, 999999));

        $user->update([
            'two_factor_code' => $code,
            'two_factor_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->sendHtmlEmail(
            $user->email,
            'Request to Disable Two-Factor Authentication',
            'Disable Two-Factor Authentication',
            'We received a request to disable Two-Factor Authentication on your ScholarlyNest account. Please use the 6-digit confirmation code below to proceed.',
            $code
        );

        return response()->json([
            'message' => 'Verification code sent to your email to authorize disabling 2FA.',
        ]);
    }

    /**
     * Disable 2FA setting using code verification.
     */
    public function disable2Fa(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if ($user->two_factor_code !== $request->code) {
            return response()->json(['message' => 'Invalid 2FA code.'], 400);
        }

        if (now()->gt($user->two_factor_code_expires_at)) {
            return response()->json(['message' => '2FA code has expired.'], 400);
        }

        $user->update([
            'two_factor_enabled' => false,
            'two_factor_code' => null,
            'two_factor_code_expires_at' => null,
        ]);
        $user->mfaMethods()->where('method', 'email')->update(['is_enabled' => false]);
        $methods = $this->mfaService->methods($user->refresh());
        $user->mfaSetting()->updateOrCreate([], [
            'is_enabled' => $methods !== [],
            'default_method' => in_array('totp', $methods, true) ? 'totp' : null,
        ]);

        return response()->json([
            'message' => 'Two-Factor Authentication has been disabled successfully.',
            'two_factor_enabled' => false,
        ]);
    }

    /**
     * Request password change code.
     */
    public function requestPasswordChangeCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = strval(random_int(100000, 999999));

        $user->update([
            'password_change_code' => $code,
            'password_change_code_expires_at' => now()->addMinutes(15),
            'password_change_verified_at' => null,
            'password_change_failed_attempts' => 0,
        ]);

        $this->sendHtmlEmail(
            $user->email,
            'Verify Your Password Change Request',
            'Dear '.$user->name.',',
            'A password change was requested for your Scholarly Nest account. Verification Details: Account Email: '.$user->email.'. Requested At: '.now()->toDateTimeString().'. Enter the verification code below in Scholarly Nest to continue changing your password. If you did not request this change, secure your account immediately and contact support.',
            $code
        );

        return response()->json([
            'message' => 'Password change verification code sent successfully to your email.',
        ]);
    }

    /**
     * Change password inside settings screen using code.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'nullable|string|size:6',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $user = $request->user();

        if (! $user->password_change_code || ! $user->password_change_verified_at) {
            return response()->json(['message' => 'Verify your password change code before updating your password.'], 403);
        }

        if (now()->gt($user->password_change_code_expires_at) || $user->password_change_verified_at->lt(now()->subMinutes(15))) {
            return response()->json(['message' => 'Password change code has expired.'], 400);
        }

        if ($request->filled('code') && ! hash_equals((string) $user->password_change_code, (string) $request->code)) {
            $this->recordPasswordChangeFailure($user);

            return response()->json(['message' => 'Invalid password change code.'], 400);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'password_change_code' => null,
            'password_change_code_expires_at' => null,
            'password_change_verified_at' => null,
            'password_change_failed_attempts' => 0,
        ]);

        $this->notificationService->send($user->email, 'Your Scholarly Nest Password Was Changed', 'Dear '.$user->name.',', [
            'Your Scholarly Nest account password was changed successfully.',
            'Account: Email: '.$user->email.'. Changed At: '.now()->toDateTimeString().'.',
            'If you made this change, no further action is required. If you did not make this change, please contact support immediately.',
        ], null, 'high', $user->id);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * Verify password change code.
     */
    public function verifyPasswordChangeCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if ($this->passwordChangeLocked($user)) {
            return response()->json(['message' => 'Too many failed verification attempts. Please request a new code.'], 429);
        }

        if (! $user->password_change_code || ! hash_equals((string) $user->password_change_code, (string) $request->code)) {
            $this->recordPasswordChangeFailure($user);

            return response()->json(['message' => 'Invalid password change code.'], 400);
        }

        if (now()->gt($user->password_change_code_expires_at)) {
            return response()->json(['message' => 'Password change code has expired.'], 400);
        }

        $user->update([
            'password_change_verified_at' => now(),
            'password_change_failed_attempts' => 0,
        ]);
        RateLimiter::clear($this->passwordChangeRateKey($user));

        return response()->json([
            'message' => 'Code verified successfully.',
        ]);
    }

    /**
     * Verify password reset token.
     */
    public function verifyPasswordResetCode(Request $request): JsonResponse
    {
        if ($this->hasAuthenticatedBearer($request)) {
            return response()->json(['message' => 'Authenticated users cannot verify password reset links from this endpoint.'], 403);
        }

        $request->validate([
            'email' => 'required|string|email',
            'token' => 'required|string|min:32',
        ]);

        if (! $this->passwordSetupService->tokenIsValid($request->email, $request->token)) {
            return response()->json(['message' => 'Invalid or expired password reset token.'], 400);
        }

        return response()->json([
            'message' => 'Reset token verified successfully.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            } else {
                // Fallback for session/cookie-based authentications
                auth()->guard('web')->logout();
            }
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->authUserPayload($request->user()),
        ]);
    }

    /**
     * Handle Google Sign In logic.
     */
    public function googleSignIn(Request $request): JsonResponse
    {
        $request->validate([
            'credential' => 'required|string',
        ]);

        $clientId = env('GOOGLE_CLIENT_ID');
        if (! $clientId) {
            return response()->json(['message' => 'Google Client ID is not configured on the server.'], 500);
        }

        $client = new Client(['client_id' => $clientId]);
        $payload = $client->verifyIdToken($request->credential);

        if (! $payload) {
            return response()->json(['message' => 'Invalid Google credential.'], 400);
        }

        $googleId = $payload['sub'];
        $email = $payload['email'];

        // Find user by Google ID or by email
        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'no_account_exists',
                'google_info' => [
                    'name' => $payload['name'] ?? '',
                    'email' => $email,
                    'google_id' => $googleId,
                ],
            ], 404);
        }

        // Link Google ID and mark verified (since google accounts are pre-verified)
        $user->google_id = $googleId;
        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        if ($this->mfaService->methods($user) !== []) {
            return $this->mfaChallengeResponse($user, $request);
        }

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $this->authUserPayload($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Handle Google Sign Up logic.
     */
    public function googleSignUp(Request $request): JsonResponse
    {
        if (! $this->publicRegistrationEnabled()) {
            return response()->json(['message' => 'Registration is currently closed.'], 403);
        }

        $request->validate([
            'credential' => 'required|string',
        ]);

        $clientId = env('GOOGLE_CLIENT_ID');
        if (! $clientId) {
            return response()->json(['message' => 'Google Client ID is not configured on the server.'], 500);
        }

        $client = new Client(['client_id' => $clientId]);
        $payload = $client->verifyIdToken($request->credential);

        if (! $payload) {
            return response()->json(['message' => 'Invalid Google credential.'], 400);
        }

        $googleId = $payload['sub'];
        $email = $payload['email'];
        $name = $payload['name'] ?? '';

        // Check if user already exists
        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if ($user) {
            return response()->json([
                'message' => 'account_already_exists',
            ], 422);
        }

        $defaultRole = $this->defaultRegistrationRole();

        // Create new user
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'google_id' => $googleId,
            'university_name' => null,
            'password' => null, // Password is null for social logins
            'email_verified_at' => now(), // Auto-verified via Google
            'role_id' => $defaultRole?->id,
        ]);

        if ($request->boolean('subscribe_newsletter')) {
            NewsletterSubscriber::firstOrCreate([
                'email' => strtolower(trim($user->email)),
            ]);
        }

        if ($this->mfaService->methods($user) !== []) {
            return $this->mfaChallengeResponse($user, $request);
        }

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $this->authUserPayload($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Request password reset link.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        if ($this->hasAuthenticatedBearer($request)) {
            return response()->json(['message' => 'Authenticated users cannot request password reset from this endpoint.'], 403);
        }

        $request->validate([
            'email' => 'required|string|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $this->passwordSetupService->sendResetLink($user);
        }

        return response()->json([
            'message' => 'Password reset link sent successfully.',
        ]);
    }

    /**
     * Reset password using a tokenized link.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        if ($this->hasAuthenticatedBearer($request)) {
            return response()->json(['message' => 'Authenticated users cannot reset password from this endpoint.'], 403);
        }

        $request->validate([
            'email' => 'required|string|email',
            'token' => 'required|string|min:32',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $user = User::where('email', $request->email)->first();
        if (! $user || ! $this->passwordSetupService->tokenIsValid($request->email, $request->token)) {
            return response()->json(['message' => 'Invalid or expired password reset token.'], 400);
        }

        $isProvisionedAccountSetup = (bool) $user->needs_password_reset;

        $user->update([
            'password' => Hash::make($request->password),
            'needs_password_reset' => false,
        ]);

        $this->passwordSetupService->consumeToken($request->email);

        $payload = [
            'message' => 'Password has been reset successfully.',
        ];

        if ($isProvisionedAccountSetup) {
            $user->tokens()->delete();
            $payload['user'] = $this->authUserPayload($user->fresh());
            $payload['access_token'] = $user->createToken('auth_token')->plainTextToken;
            $payload['token_type'] = 'Bearer';
            $payload['auto_login'] = true;
        }

        return response()->json($payload);
    }

    /**
     * Send a beautifully styled welcome HTML email to the user.
     */
    private function sendWelcomeHtmlEmail(string $email, string $name, ?string $createPasswordLink): void
    {
        $subject = 'Welcome to ScholarlyNest!';
        $title = 'Welcome to ScholarlyNest, '.htmlspecialchars($name).'!';

        $action = null;
        if ($createPasswordLink) {
            $action = [
                'text' => 'Create Your Password',
                'url' => $createPasswordLink,
            ];
            $description = 'An administrator has created your ScholarlyNest account. To complete your setup and begin collaborating, please click the button below to establish your password.';
        } else {
            $description = 'Thank you for verifying your email address! Your registration is complete, and your ScholarlyNest account is now active. We are thrilled to welcome you to our scientific research community.';
        }

        $user = User::where('email', $email)->first();
        $userId = $user ? $user->id : null;

        $this->notificationService->send(
            $email,
            $subject,
            $title,
            [$description],
            $action,
            'high',
            $userId
        );
    }

    /**
     * Enforce password reset for newly provisioned user.
     */
    public function resetEnforcedPassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->needs_password_reset) {
            return response()->json(['message' => 'Password reset is not required.'], 400);
        }

        $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $user->update([
            'password' => Hash::make($request->password),
            'needs_password_reset' => false,
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
            'user' => $this->authUserPayload($user),
        ]);
    }

    /**
     * Update user profile information.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'profile_image' => 'nullable|string|max:2048',
            'profile_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'university_name' => 'nullable|string|max:255',
            'role_id' => 'prohibited',
            'role' => 'prohibited',
            'roles' => 'prohibited',
            'permissions' => 'prohibited',
            'magazine_ids' => 'prohibited',
            'assigned_magazines' => 'prohibited',
        ]);

        $profileImage = $user->profile_image;
        if ($request->filled('profile_image_upload_id')) {
            $profileImage = app(CleanUploadResolver::class)->cleanKey($user, $request->profile_image_upload_id, 'profile_image');
        } elseif ($request->has('profile_image')) {
            $requestedImage = $request->input('profile_image');
            $currentUrl = app(MediaStorageService::class)->applicationUrl($user->profile_image);
            if ($requestedImage === null || $requestedImage === '') {
                $profileImage = null;
            } elseif ($requestedImage !== $user->profile_image && $requestedImage !== $currentUrl) {
                return response()->json(['message' => 'Profile image must be selected from a clean profile image upload.'], 422);
            }
        }

        $user->update([
            'name' => $request->has('name') ? $request->name : $user->name,
            'profile_image' => $profileImage,
            'university_name' => $request->has('university_name') ? $request->university_name : $user->university_name,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->authUserPayload($user),
        ]);
    }

    /**
     * Request a verification code to be sent to current email for change authorization.
     */
    public function requestCurrentEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = sprintf('%06d', random_int(100000, 999999));

        $user->update([
            'email_change_code' => $code,
            'email_change_code_expires_at' => now()->addMinutes(15),
            'current_email_verified' => false,
        ]);

        // Send email
        $this->notificationService->send(
            $user->email,
            'Email Change Verification Code',
            'Verify Current Email Ownership',
            [
                'You have requested to change your ScholarlyNest account email.',
                'Please use the 6-digit verification code below to authorize the first step of this change:',
                'Verification Code: '.$code,
                'This code will expire in 15 minutes. If you did not initiate this change, please ignore this email and secure your account credentials.',
            ],
            null,
            'high',
            $user->id
        );

        return response()->json([
            'message' => 'Verification code sent to your current email address.',
        ]);
    }

    /**
     * Verify the code sent to the current email address.
     */
    public function verifyCurrentEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        if (! $user->email_change_code || $user->email_change_code !== $request->code) {
            return response()->json(['message' => 'Invalid verification code.'], 400);
        }

        if (now()->gt($user->email_change_code_expires_at)) {
            return response()->json(['message' => 'Verification code has expired.'], 400);
        }

        $user->update([
            'email_change_code' => null,
            'email_change_code_expires_at' => null,
            'current_email_verified' => true,
        ]);

        return response()->json([
            'message' => 'Current email verified. You may now specify your new email address.',
        ]);
    }

    /**
     * Request a verification code to be sent to the new email address.
     */
    public function requestNewEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->current_email_verified) {
            return response()->json(['message' => 'Please verify ownership of your current email first.'], 403);
        }

        $request->validate([
            'email' => 'required|string|email|max:255|unique:users,email',
        ]);

        $code = sprintf('%06d', random_int(100000, 999999));

        $user->update([
            'pending_email' => $request->email,
            'new_email_verification_code' => $code,
            'new_email_verification_code_expires_at' => now()->addMinutes(15),
        ]);

        // Send email to new email address
        $this->notificationService->send(
            $request->email,
            'Confirm New Email Address',
            'Verify New Email Ownership',
            [
                'A request was made to set this email as the primary academic email for your ScholarlyNest account.',
                'Please use the 6-digit confirmation code below to complete the transition:',
                'Confirmation Code: '.$code,
                'This code will expire in 15 minutes. If you did not request this update, no action is required.',
            ],
            null,
            'high',
            $user->id
        );

        return response()->json([
            'message' => 'Verification code sent to your new email address.',
        ]);
    }

    /**
     * Verify the code sent to the new email address to complete the update.
     */
    public function verifyNewEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->current_email_verified || ! $user->pending_email) {
            return response()->json(['message' => 'Verify current email and submit a new email address first.'], 403);
        }

        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        // Enforce uniqueness check again on final submit to prevent race conditions
        $exists = User::where('email', $user->pending_email)->where('id', '!=', $user->id)->exists();
        if ($exists) {
            return response()->json(['message' => 'The pending email address has already been taken.'], 422);
        }

        if (! $user->new_email_verification_code || $user->new_email_verification_code !== $request->code) {
            return response()->json(['message' => 'Invalid verification code.'], 400);
        }

        if (now()->gt($user->new_email_verification_code_expires_at)) {
            return response()->json(['message' => 'Verification code has expired.'], 400);
        }

        // Apply change
        $user->update([
            'email' => $user->pending_email,
            'pending_email' => null,
            'new_email_verification_code' => null,
            'new_email_verification_code_expires_at' => null,
            'current_email_verified' => false,
        ]);

        return response()->json([
            'message' => 'Email updated successfully.',
            'user' => $this->authUserPayload($user),
        ]);
    }

    private function publicRegistrationEnabled(): bool
    {
        $value = Setting::where('key', 'registration_enabled')->value('value');
        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function defaultRegistrationRole(): ?Role
    {
        $roleName = Setting::where('key', 'default_registration_role')->value('value') ?? 'author';
        $role = Role::where('name', $roleName)->first();

        if (! $this->isRegistrationEligibleRole($role)) {
            $role = Role::where('name', 'author')->first();
        }

        return $role;
    }

    private function isRegistrationEligibleRole(?Role $role): bool
    {
        return $role && $role->name === 'author';
    }

    public function userPayload(User $user): array
    {
        $user->loadMissing('role.permissions');
        $role = $user->role;
        $permissionNames = $role?->permissions
            ? $role->permissions->pluck('name')->values()->all()
            : [];
        $isRbacAdmin = $user->hasRole('super_admin') || $user->hasRole('admin');

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_image' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
            'profile_image_url' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
            'university_name' => $user->university_name,
            'email_verified_at' => $user->email_verified_at,
            'needs_password_reset' => (bool) $user->needs_password_reset,
            'two_factor_enabled' => $this->mfaService->methods($user) !== [],
            'role_id' => $user->role_id,
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ] : null,
            'roles' => $role ? [[
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ]] : [],
            'capabilities' => $this->capabilityPayload($permissionNames, $user),
        ];

        if ($isRbacAdmin) {
            $payload['permissions'] = $role?->permissions
                ? $role->permissions->map(fn ($permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'module' => $permission->module,
                ])->values()->all()
                : [];
        }

        return $payload;
    }

    private function authUserPayload(User $user): array
    {
        return $this->userPayload($user);
    }

    private function mfaChallengeResponse(User $user, Request $request): JsonResponse
    {
        $result = $this->mfaService->createChallenge($user, $request);
        if ($result['email_code']) {
            $this->sendHtmlEmail(
                $user->email,
                'Your Two-Factor Authentication Code',
                'Two-Factor Authentication',
                'A sign-in attempt was detected for your Scholarly Nest profile. Use the 6-digit verification code below to authorize your session.',
                $result['email_code']
            );
        }

        return response()->json(array_merge([
            'message' => '2fa_required',
            'requires_mfa' => true,
            'mfa_challenge_token' => $result['token'],
            'available_methods' => $result['methods'],
            'default_method' => $result['default'],
        ], $this->mfaService->challengeState($result['challenge'])), 202);
    }

    private function capabilityPayload(array $permissionNames, User $user): array
    {
        $permissions = array_fill_keys($permissionNames, true);
        $isSuperAdmin = $user->hasRole('super_admin');
        $allowList = [
            'articles.view-any',
            'articles.view-own',
            'articles.create',
            'articles.edit-own',
            'articles.approve',
            'articles.auto-approve',
            'articles.manage-assets',
            'magazines.view-any',
            'magazines.view-own',
            'magazines.create',
            'magazines.edit',
            'magazines.delete',
            'magazines.pages.manage',
            'seo.articles',
            'seo.magazines',
            'seo.cms-pages',
            'roles.view-any',
            'settings.view-any',
            'settings.manage',
            'footer.manage',
            'newsletters.view-any',
            'newsletters.send',
        ];

        $capabilities = [];
        foreach ($allowList as $permission) {
            $capabilities[$permission] = $isSuperAdmin || isset($permissions[$permission]);
        }

        $capabilities['can_view_author_dashboard'] = $user->hasRole('author') || ($capabilities['articles.view-own'] ?? false);
        $capabilities['can_view_editor_dashboard'] = $user->hasRole('editor') || ($capabilities['articles.approve'] ?? false);
        $capabilities['can_view_sub_editor_dashboard'] = $user->hasRole('sub_editor');
        $capabilities['can_view_reviewer_dashboard'] = $user->hasRole('reviewer');
        $capabilities['can_view_publisher_dashboard'] = $user->hasRole('publisher');
        $capabilities['can_view_copy_editor_dashboard'] = $user->hasRole('copy_editor');
        $capabilities['can_view_proofreader_dashboard'] = $user->hasRole('proofreader');
        $capabilities['can_manage_assigned_magazines'] = ($capabilities['magazines.view-any'] ?? false) || ($capabilities['magazines.view-own'] ?? false);
        $capabilities['can_manage_rbac'] = $isSuperAdmin || ($capabilities['roles.view-any'] ?? false);

        return $capabilities;
    }

    private function passwordChangeRateKey(User $user): string
    {
        return 'password-change-verify:'.$user->id;
    }

    private function hasAuthenticatedBearer(Request $request): bool
    {
        return $request->bearerToken() && auth('sanctum')->user() !== null;
    }

    private function passwordChangeLocked(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->passwordChangeRateKey($user), 5)
            || (int) $user->password_change_failed_attempts >= 5;
    }

    private function recordPasswordChangeFailure(User $user): void
    {
        RateLimiter::hit($this->passwordChangeRateKey($user), 15 * 60);
        $user->forceFill([
            'password_change_verified_at' => null,
            'password_change_failed_attempts' => min(255, ((int) $user->password_change_failed_attempts) + 1),
        ])->save();
    }
}
