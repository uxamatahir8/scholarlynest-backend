<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
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
            '<div class="code-box"><div class="code-value">' . htmlspecialchars($code) . '</div></div>',
            '<div style="font-size: 12px; color: #a1a1aa; text-align: center; margin-top: 16px;">This code is highly sensitive and is valid for the next 15 minutes. Do not share this code with anyone.</div>'
        ];

        $this->notificationService->send(
            $email,
            $subject,
            $title,
            $bodyLines,
            $action,
            'high',
            $userId
        );
    }


    /**
     * Handle public user registration.
     */
    public function register(Request $request): JsonResponse
    {
        if (!$this->publicRegistrationEnabled()) {
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
                Password::min(8)->letters()->mixedCase()->numbers()->symbols()
            ],
        ]);

        $defaultRole = $this->defaultRegistrationRole();
        $code = strval(mt_rand(100000, 999999));

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
            \App\Models\NewsletterSubscriber::firstOrCreate([
                'email' => strtolower(trim($user->email)),
            ]);
        }

        $this->sendHtmlEmail(
            $user->email,
            "Verify Your Scholar Account",
            "Verify Your Account",
            "Thank you for registering at ScholarlyNest. Please use the 6-digit confirmation code below to verify your email address.",
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

        if (!$user) {
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

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified.'], 400);
        }

        $code = strval(mt_rand(100000, 999999));
        $user->update([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->sendHtmlEmail(
            $user->email,
            "Verify Your Scholar Account",
            "Verify Your Account",
            "Thank you for registering at ScholarlyNest. Please use the 6-digit confirmation code below to verify your email address.",
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
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // If email is not verified, return verification required status
        if (!$user->email_verified_at) {
            if (!$user->verification_code || now()->gt($user->verification_code_expires_at)) {
                $code = strval(mt_rand(100000, 999999));
                $user->update([
                    'verification_code' => $code,
                    'verification_code_expires_at' => now()->addMinutes(15),
                ]);
                $this->sendHtmlEmail(
                    $user->email,
                    "Verify Your Scholar Account",
                    "Verify Your Account",
                    "Thank you for registering at ScholarlyNest. Please use the 6-digit confirmation code below to verify your email address.",
                    $code
                );
            }
            return response()->json([
                'message' => 'verification_required',
                'email' => $user->email,
            ], 403);
        }

        // If Two-Factor Authentication is enabled, trigger the validation workflow
        if ($user->two_factor_enabled) {
            $code = strval(mt_rand(100000, 999999));
            $user->update([
                'two_factor_code' => $code,
                'two_factor_code_expires_at' => now()->addMinutes(15),
            ]);
            $this->sendHtmlEmail(
                $user->email,
                "Your Two-Factor Authentication Code",
                "Two-Factor Authentication",
                "A sign-in attempt was detected for your ScholarlyNest profile. Use the 6-digit verification code below to authorize your session.",
                $code
            );
            return response()->json([
                'message' => '2fa_required',
                'email' => $user->email,
            ], 202);
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

        if (!$user) {
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
        $code = strval(mt_rand(100000, 999999));

        $user->update([
            'two_factor_code' => $code,
            'two_factor_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->sendHtmlEmail(
            $user->email,
            "Request to Disable Two-Factor Authentication",
            "Disable Two-Factor Authentication",
            "We received a request to disable Two-Factor Authentication on your ScholarlyNest account. Please use the 6-digit confirmation code below to proceed.",
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
        $code = strval(mt_rand(100000, 999999));

        $user->update([
            'password_change_code' => $code,
            'password_change_code_expires_at' => now()->addMinutes(15),
        ]);

        $this->sendHtmlEmail(
            $user->email,
            "Request to Change Password",
            "Change Password Authorization",
            "We received a request to update your ScholarlyNest account password. Please enter the 6-digit validation code below to proceed.",
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
            'code' => 'required|string|size:6',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols()
            ],
        ]);

        $user = $request->user();

        if ($user->password_change_code !== $request->code) {
            return response()->json(['message' => 'Invalid password change code.'], 400);
        }

        if (now()->gt($user->password_change_code_expires_at)) {
            return response()->json(['message' => 'Password change code has expired.'], 400);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'password_change_code' => null,
            'password_change_code_expires_at' => null,
        ]);

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

        if ($user->password_change_code !== $request->code) {
            return response()->json(['message' => 'Invalid password change code.'], 400);
        }

        if (now()->gt($user->password_change_code_expires_at)) {
            return response()->json(['message' => 'Password change code has expired.'], 400);
        }

        return response()->json([
            'message' => 'Code verified successfully.',
        ]);
    }

    /**
     * Verify password reset code.
     */
    public function verifyPasswordResetCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|exists:users,email',
            'code' => 'required|string|size:6',
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$reset || $reset->token !== $request->code) {
            return response()->json(['message' => 'Invalid password reset code.'], 400);
        }

        if (now()->subMinutes(15)->gt($reset->created_at)) {
            return response()->json(['message' => 'Password reset code has expired.'], 400);
        }

        return response()->json([
            'message' => 'Code verified successfully.',
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
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->authUserPayload($request->user())
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
        if (!$clientId) {
            return response()->json(['message' => 'Google Client ID is not configured on the server.'], 500);
        }

        $client = new \Google\Client(['client_id' => $clientId]);
        $payload = $client->verifyIdToken($request->credential);

        if (!$payload) {
            return response()->json(['message' => 'Invalid Google credential.'], 400);
        }

        $googleId = $payload['sub'];
        $email = $payload['email'];

        // Find user by Google ID or by email
        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'no_account_exists',
                'google_info' => [
                    'name' => $payload['name'] ?? '',
                    'email' => $email,
                    'google_id' => $googleId,
                ]
            ], 404);
        }

        // Link Google ID and mark verified (since google accounts are pre-verified)
        $user->google_id = $googleId;
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        // If Two-Factor Authentication is enabled, trigger the validation workflow
        if ($user->two_factor_enabled) {
            $code = strval(mt_rand(100000, 999999));
            $user->update([
                'two_factor_code' => $code,
                'two_factor_code_expires_at' => now()->addMinutes(15),
            ]);
            $this->sendHtmlEmail(
                $user->email,
                "Your Two-Factor Authentication Code",
                "Two-Factor Authentication",
                "A sign-in attempt was detected for your ScholarlyNest profile. Use the 6-digit verification code below to authorize your session.",
                $code
            );
            return response()->json([
                'message' => '2fa_required',
                'email' => $user->email,
            ], 202);
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
        if (!$this->publicRegistrationEnabled()) {
            return response()->json(['message' => 'Registration is currently closed.'], 403);
        }

        $request->validate([
            'credential' => 'required|string',
        ]);

        $clientId = env('GOOGLE_CLIENT_ID');
        if (!$clientId) {
            return response()->json(['message' => 'Google Client ID is not configured on the server.'], 500);
        }

        $client = new \Google\Client(['client_id' => $clientId]);
        $payload = $client->verifyIdToken($request->credential);

        if (!$payload) {
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
            \App\Models\NewsletterSubscriber::firstOrCreate([
                'email' => strtolower(trim($user->email)),
            ]);
        }

        // If Two-Factor Authentication is enabled, trigger the validation workflow
        if ($user->two_factor_enabled) {
            $code = strval(mt_rand(100000, 999999));
            $user->update([
                'two_factor_code' => $code,
                'two_factor_code_expires_at' => now()->addMinutes(15),
            ]);
            $this->sendHtmlEmail(
                $user->email,
                "Your Two-Factor Authentication Code",
                "Two-Factor Authentication",
                "A sign-in attempt was detected for your ScholarlyNest profile. Use the 6-digit verification code below to authorize your session.",
                $code
            );
            return response()->json([
                'message' => '2fa_required',
                'email' => $user->email,
            ], 202);
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
     * Request password reset code.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|exists:users,email',
        ]);

        $code = strval(mt_rand(100000, 999999));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => $code,
                'created_at' => now(),
            ]
        );

        $frontendUrl = env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com');
        $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?email=' . urlencode($request->email) . '&code=' . urlencode($code);
        $action = [
            'text' => 'Reset Your Password',
            'url' => $resetUrl,
        ];

        $this->sendHtmlEmail(
            $request->email,
            "Reset Your Password",
            "Password Reset Request",
            "We received a request to reset your password. Use the 6-digit confirmation code below or click the link to verify ownership and authorize.",
            $code,
            $action
        );

        return response()->json([
            'message' => 'Password reset code sent successfully.',
        ]);
    }

    /**
     * Reset password using the code.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|exists:users,email',
            'code' => 'required|string|size:6',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols()
            ],
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$reset || $reset->token !== $request->code) {
            return response()->json(['message' => 'Invalid password reset code.'], 400);
        }

        if (now()->subMinutes(15)->gt($reset->created_at)) {
            return response()->json(['message' => 'Password reset code has expired.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Delete used token
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }

    /**
     * Send a beautifully styled welcome HTML email to the user.
     */
    private function sendWelcomeHtmlEmail(string $email, string $name, ?string $createPasswordLink): void
    {
        $subject = "Welcome to ScholarlyNest!";
        $title = "Welcome to ScholarlyNest, " . htmlspecialchars($name) . "!";
        
        $action = null;
        if ($createPasswordLink) {
            $action = [
                'text' => 'Create Your Password',
                'url' => $createPasswordLink,
            ];
            $description = "An administrator has created your ScholarlyNest account. To complete your setup and begin collaborating, please click the button below to establish your password.";
        } else {
            $description = "Thank you for verifying your email address! Your registration is complete, and your ScholarlyNest account is now active. We are thrilled to welcome you to our scientific research community.";
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
        if (!$user->needs_password_reset) {
            return response()->json(['message' => 'Password reset is not required.'], 400);
        }

        $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                \Illuminate\Validation\Rules\Password::min(8)->letters()->mixedCase()->numbers()->symbols()
            ],
        ]);

        $user->update([
            'password' => \Illuminate\Support\Facades\Hash::make($request->password),
            'needs_password_reset' => false,
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
            'user' => $this->authUserPayload($user)
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
            'university_name' => 'nullable|string|max:255',
        ]);

        $user->update([
            'name' => $request->has('name') ? $request->name : $user->name,
            'profile_image' => $request->has('profile_image') ? $request->profile_image : $user->profile_image,
            'university_name' => $request->has('university_name') ? $request->university_name : $user->university_name,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->authUserPayload($user)
        ]);
    }

    /**
     * Request a verification code to be sent to current email for change authorization.
     */
    public function requestCurrentEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = sprintf("%06d", mt_rand(100000, 999999));

        $user->update([
            'email_change_code' => $code,
            'email_change_code_expires_at' => now()->addMinutes(15),
            'current_email_verified' => false,
        ]);

        // Send email
        $this->notificationService->send(
            $user->email,
            "Email Change Verification Code",
            "Verify Current Email Ownership",
            [
                "You have requested to change your ScholarlyNest account email.",
                "Please use the 6-digit verification code below to authorize the first step of this change:",
                "Verification Code: " . $code,
                "This code will expire in 15 minutes. If you did not initiate this change, please ignore this email and secure your account credentials."
            ],
            null,
            'high',
            $user->id
        );

        return response()->json([
            'message' => 'Verification code sent to your current email address.'
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

        if (!$user->email_change_code || $user->email_change_code !== $request->code) {
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
            'message' => 'Current email verified. You may now specify your new email address.'
        ]);
    }

    /**
     * Request a verification code to be sent to the new email address.
     */
    public function requestNewEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->current_email_verified) {
            return response()->json(['message' => 'Please verify ownership of your current email first.'], 403);
        }

        $request->validate([
            'email' => 'required|string|email|max:255|unique:users,email',
        ]);

        $code = sprintf("%06d", mt_rand(100000, 999999));

        $user->update([
            'pending_email' => $request->email,
            'new_email_verification_code' => $code,
            'new_email_verification_code_expires_at' => now()->addMinutes(15),
        ]);

        // Send email to new email address
        $this->notificationService->send(
            $request->email,
            "Confirm New Email Address",
            "Verify New Email Ownership",
            [
                "A request was made to set this email as the primary academic email for your ScholarlyNest account.",
                "Please use the 6-digit confirmation code below to complete the transition:",
                "Confirmation Code: " . $code,
                "This code will expire in 15 minutes. If you did not request this update, no action is required."
            ],
            null,
            'high',
            $user->id
        );

        return response()->json([
            'message' => 'Verification code sent to your new email address.'
        ]);
    }

    /**
     * Verify the code sent to the new email address to complete the update.
     */
    public function verifyNewEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->current_email_verified || !$user->pending_email) {
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

        if (!$user->new_email_verification_code || $user->new_email_verification_code !== $request->code) {
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
            'user' => $this->authUserPayload($user)
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

        if (!$this->isRegistrationEligibleRole($role)) {
            $role = Role::where('name', 'author')->first();
        }

        return $role;
    }

    private function isRegistrationEligibleRole(?Role $role): bool
    {
        return $role && $role->name === 'author';
    }

    private function authUserPayload(User $user): array
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
            'profile_image' => $user->profile_image,
            'university_name' => $user->university_name,
            'email_verified_at' => $user->email_verified_at,
            'needs_password_reset' => (bool) $user->needs_password_reset,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
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
}
