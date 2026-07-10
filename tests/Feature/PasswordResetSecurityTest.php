<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Role $authorRole;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorRole = Role::create([
            'name' => 'author',
            'display_name' => 'Author',
            'is_system' => true
        ]);

        $this->user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => Hash::make('Password@1234'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Forgot-password response is generic for existing and non-existing emails.
     */
    public function test_forgot_password_response_is_generic_for_existing_and_non_existing_emails(): void
    {
        // Existing email
        $response1 = $this->postJson('/api/forgot-password', [
            'email' => 'john@example.com',
        ]);
        $response1->assertStatus(200);
        $response1->assertJson(['message' => 'Password reset link sent successfully.']);

        // Non-existing email
        $response2 = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);
        $response2->assertStatus(200);
        $response2->assertJson(['message' => 'Password reset link sent successfully.']);
    }

    /**
     * Reset link generation uses the configured frontend URL safely.
     */
    public function test_reset_link_generation_uses_configured_frontend_url(): void
    {
        config(['app.url_frontend' => 'https://custom-frontend.com']);
        putenv('APP_URL_FRONTEND=https://custom-frontend.com');
        $_ENV['APP_URL_FRONTEND'] = 'https://custom-frontend.com';
        $_SERVER['APP_URL_FRONTEND'] = 'https://custom-frontend.com';

        $this->postJson('/api/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $reset = DB::table('password_reset_tokens')->where('email', 'john@example.com')->first();
        $this->assertNotNull($reset);
        // The saved token must be hashed, so it should not look like a plaintext reset token.
        $this->assertNotEquals(6, strlen($reset->token));
        $log = NotificationLog::where('recipient_email', 'john@example.com')->latest()->first();
        $this->assertStringStartsWith('https://custom-frontend.com/reset-password?', $log->payload['action']['url']);
        $this->assertStringContainsString('token=', $log->payload['action']['url']);
        $this->assertStringNotContainsString('code=', $log->payload['action']['url']);
        putenv('APP_URL_FRONTEND');
        unset($_ENV['APP_URL_FRONTEND'], $_SERVER['APP_URL_FRONTEND']);
    }

    /**
     * Reset API response never returns the reset token.
     */
    public function test_reset_api_response_never_returns_the_reset_code(): void
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'john@example.com',
        ]);
        $response->assertStatus(200);
        $response->assertJsonMissing(['code']);
        $response->assertJsonMissing(['token']);
    }

    /**
     * Missing email or token is rejected.
     */
    public function test_missing_email_or_code_is_rejected(): void
    {
        // Missing email
        $response = $this->postJson('/api/password/verify-reset-code', [
            'token' => str_repeat('a', 64),
        ]);
        $response->assertStatus(422);

        // Missing code
        $response = $this->postJson('/api/password/verify-reset-code', [
            'email' => 'john@example.com',
        ]);
        $response->assertStatus(422);

        // Reset password - missing email
        $response = $this->postJson('/api/reset-password', [
            'token' => str_repeat('a', 64),
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);
        $response->assertStatus(422);
    }

    /**
     * Invalid token is rejected.
     */
    public function test_invalid_token_is_rejected(): void
    {
        // Generate a valid code in the DB first
        $this->postJson('/api/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $response = $this->postJson('/api/password/verify-reset-code', [
            'email' => 'john@example.com',
            'token' => str_repeat('0', 64),
        ]);
        $response->assertStatus(400);
        $response->assertJson(['message' => 'Invalid or expired password reset token.']);
    }

    /**
     * Reset token expires.
     */
    public function test_reset_token_expires(): void
    {
        // Insert an expired token manually
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => 'john@example.com'],
            [
                'token' => Hash::make(str_repeat('a', 64)),
                'created_at' => now()->subMinutes(61),
            ]
        );

        $response = $this->postJson('/api/password/verify-reset-code', [
            'email' => 'john@example.com',
            'token' => str_repeat('a', 64),
        ]);
        $response->assertStatus(400);
        $response->assertJson(['message' => 'Invalid or expired password reset token.']);
    }

    /**
     * Reset token cannot be used twice.
     */
    public function test_reset_token_cannot_be_used_twice(): void
    {
        // Insert a token manually
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => 'john@example.com'],
            [
                'token' => Hash::make(str_repeat('b', 64)),
                'created_at' => now(),
            ]
        );

        // First reset - succeeds
        $response = $this->postJson('/api/reset-password', [
            'email' => 'john@example.com',
            'token' => str_repeat('b', 64),
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);
        $response->assertStatus(200);

        // Second reset with same code - fails
        $response2 = $this->postJson('/api/reset-password', [
            'email' => 'john@example.com',
            'token' => str_repeat('b', 64),
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);
        $response2->assertStatus(400);
    }

    /**
     * Password is changed only with valid active token.
     */
    public function test_password_is_changed_only_with_valid_active_token(): void
    {
        // Attempt password change without a token
        $response = $this->postJson('/api/reset-password', [
            'email' => 'john@example.com',
            'token' => str_repeat('c', 64),
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);
        $response->assertStatus(400);

        // Insert token
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => 'john@example.com'],
            [
                'token' => Hash::make(str_repeat('c', 64)),
                'created_at' => now(),
            ]
        );

        // Reset with invalid password validation (too simple)
        $response = $this->postJson('/api/reset-password', [
            'email' => 'john@example.com',
            'token' => str_repeat('c', 64),
            'password' => 'simple',
            'password_confirmation' => 'simple',
        ]);
        $response->assertStatus(422);

        // Reset with valid token
        $response = $this->postJson('/api/reset-password', [
            'email' => 'john@example.com',
            'token' => str_repeat('c', 64),
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);
        $response->assertStatus(200);

        // Verify we can login with the new password
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'john@example.com',
            'password' => 'NewPassword@123',
        ]);
        $loginResponse->assertStatus(200);
    }
}
