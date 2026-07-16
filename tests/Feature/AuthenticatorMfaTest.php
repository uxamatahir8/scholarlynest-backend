<?php

namespace Tests\Feature;

use App\Models\MfaChallenge;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMfaMethod;
use App\Models\UserMfaSetting;
use App\Services\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticatorMfaTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->totp = app(Google2FA::class);
    }

    public function test_user_can_start_setup_without_enabling_totp_or_storing_plaintext_secret(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/me/security/mfa/totp/setup')->assertOk()
            ->assertJsonStructure(['otpauth_uri', 'manual_setup_key', 'expires_at']);

        $secret = $response->json('manual_setup_key');
        $this->assertStringStartsWith('otpauth://totp/Scholarly%20Nest:', $response->json('otpauth_uri'));
        $method = UserMfaMethod::where('user_id', $user->id)->where('method', 'totp')->firstOrFail();
        $this->assertFalse($method->is_enabled);
        $this->assertFalse($method->is_verified);
        $this->assertNotSame($secret, $method->pending_secret_encrypted);
        $this->assertSame($secret, Crypt::decryptString($method->pending_secret_encrypted));
    }

    public function test_account_page_requests_do_not_consume_the_totp_setup_rate_limit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        for ($request = 0; $request < 6; $request++) {
            $this->getJson('/api/me/security/mfa')->assertOk();
        }

        $this->postJson('/api/me/security/mfa/totp/setup')->assertOk();
    }

    public function test_valid_setup_code_enables_totp_and_returns_hashed_one_time_recovery_codes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $secret = $this->postJson('/api/me/security/mfa/totp/setup')->json('manual_setup_key');

        $response = $this->postJson('/api/me/security/mfa/totp/verify', [
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->assertJsonCount(10, 'recovery_codes');

        $method = UserMfaMethod::where('user_id', $user->id)->where('method', 'totp')->firstOrFail();
        $this->assertTrue($method->is_enabled);
        $this->assertTrue($method->is_verified);
        $this->assertNull($method->pending_secret_encrypted);
        $this->assertNotSame($secret, $method->secret_encrypted);
        $plainRecovery = str_replace('-', '', $response->json('recovery_codes.0'));
        $this->assertTrue($user->mfaRecoveryCodes()->get()->contains(fn ($stored) => Hash::check($plainRecovery, $stored->code_hash)));
    }

    public function test_invalid_setup_attempts_are_persisted_and_do_not_enable_totp(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/me/security/mfa/totp/setup')->assertOk();

        $this->postJson('/api/me/security/mfa/totp/verify', ['code' => '000000'])->assertUnprocessable();

        $method = UserMfaMethod::where('user_id', $user->id)->where('method', 'totp')->firstOrFail();
        $this->assertSame(1, $method->pending_attempts);
        $this->assertFalse($method->is_enabled);
    }

    public function test_login_requires_challenge_and_valid_totp_consumes_it_before_issuing_token(): void
    {
        [$user, $secret] = $this->totpUser();
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJsonPath('requires_mfa', true)
            ->assertJsonPath('available_methods.0', 'totp')
            ->assertJsonPath('required_methods', ['totp'])
            ->assertJsonPath('next_method', 'totp')
            ->assertJsonMissing(['access_token']);
        $token = $response->json('mfa_challenge_token');

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token,
            'method' => 'totp',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['user', 'access_token', 'token_type']);

        $this->assertNotNull(MfaChallenge::firstOrFail()->consumed_at);
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'totp', 'code' => $this->totp->getCurrentOtp($secret),
        ])->assertUnprocessable();
    }

    public function test_email_only_login_requires_email_verification_before_issuing_token(): void
    {
        $user = $this->emailUser();
        [$token, $challenge] = $this->loginChallenge($user);
        $challenge->update(['email_code_hash' => Hash::make('123456')]);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'email', 'code' => '123456',
        ])->assertOk()->assertJsonPath('status', 'complete')->assertJsonStructure(['access_token']);
    }

    public function test_email_and_totp_require_email_then_totp_before_issuing_token(): void
    {
        [$user, $secret] = $this->bothMethodsUser();
        [$token, $challenge] = $this->loginChallenge($user);
        $challenge->update(['email_code_hash' => Hash::make('123456')]);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'email', 'code' => '123456',
        ])->assertStatus(202)
            ->assertJsonPath('verified_methods', ['email'])
            ->assertJsonPath('remaining_methods', ['totp'])
            ->assertJsonPath('next_method', 'totp')
            ->assertJsonMissing(['access_token']);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'totp', 'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->assertJsonPath('status', 'complete')->assertJsonStructure(['access_token']);
    }

    public function test_email_and_totp_can_be_verified_totp_first_without_issuing_token_early(): void
    {
        [$user, $secret] = $this->bothMethodsUser();
        [$token, $challenge] = $this->loginChallenge($user);
        $challenge->update(['email_code_hash' => Hash::make('123456')]);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'totp', 'code' => $this->totp->getCurrentOtp($secret),
        ])->assertStatus(202)
            ->assertJsonPath('verified_methods', ['totp'])
            ->assertJsonPath('remaining_methods', ['email'])
            ->assertJsonMissing(['access_token']);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'email', 'code' => '123456',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_recovery_code_is_rejected_before_three_failed_totp_attempts(): void
    {
        [$user] = $this->totpUser();
        $code = app(MfaService::class)->regenerateRecoveryCodes($user)[0];
        [$token] = $this->loginChallenge($user);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'recovery_code', 'code' => $code,
        ])->assertUnprocessable()->assertJsonPath('recovery_code_allowed', false);
        $this->assertNull($user->mfaRecoveryCodes()->firstOrFail()->used_at);
    }

    public function test_recovery_code_becomes_available_after_three_failed_totp_attempts(): void
    {
        [$user] = $this->totpUser();
        [$token] = $this->loginChallenge($user);

        $this->failTotp($token, 2)->assertJsonPath('recovery_code_allowed', false);
        $this->failTotp($token)->assertJsonPath('recovery_code_allowed', true)
            ->assertJsonPath('message', 'Invalid authenticator code. You may use a recovery code if you cannot access your authenticator app.');
        $this->assertSame(3, MfaChallenge::firstOrFail()->totp_attempt_count);
    }

    public function test_valid_recovery_code_after_three_totp_failures_satisfies_totp(): void
    {
        [$user] = $this->totpUser();
        $code = app(MfaService::class)->regenerateRecoveryCodes($user)[0];
        [$token] = $this->loginChallenge($user);
        $this->failTotp($token, 3);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'recovery_code', 'code' => $code,
        ])->assertOk()->assertJsonPath('verified_methods', ['totp'])->assertJsonStructure(['access_token']);
    }

    public function test_recovery_code_satisfies_totp_but_does_not_skip_unverified_email(): void
    {
        [$user] = $this->bothMethodsUser();
        $code = app(MfaService::class)->regenerateRecoveryCodes($user)[0];
        [$token, $challenge] = $this->loginChallenge($user);
        $challenge->update(['email_code_hash' => Hash::make('123456')]);
        $this->failTotp($token, 3);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'recovery_code', 'code' => $code,
        ])->assertStatus(202)
            ->assertJsonPath('verified_methods', ['totp'])
            ->assertJsonPath('remaining_methods', ['email'])
            ->assertJsonMissing(['access_token']);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'email', 'code' => '123456',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_invalid_attempts_are_counted_per_method_and_limited(): void
    {
        [$user] = $this->totpUser();
        [$token] = $this->loginChallenge($user);
        $this->failTotp($token, MfaService::MAX_METHOD_ATTEMPTS);

        $challenge = MfaChallenge::firstOrFail();
        $this->assertSame(MfaService::MAX_METHOD_ATTEMPTS, $challenge->attempts);
        $this->assertSame(MfaService::MAX_METHOD_ATTEMPTS, $challenge->totp_attempt_count);
        $this->failTotp($token)->assertJsonPath('message', 'Too many failed attempts for this MFA method. Please sign in again.');
        $this->assertSame(MfaService::MAX_METHOD_ATTEMPTS, $challenge->refresh()->totp_attempt_count);
    }

    public function test_expired_challenge_cannot_be_completed(): void
    {
        [$user] = $this->totpUser();
        [$token, $challenge] = $this->loginChallenge($user);

        $challenge->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'totp', 'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    }

    public function test_super_admin_with_email_and_totp_must_verify_both_methods(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['is_system' => true]);
        [$user, $secret] = $this->bothMethodsUser(['role_id' => $role->id]);
        [$token, $challenge] = $this->loginChallenge($user);
        $challenge->update(['email_code_hash' => Hash::make('123456')]);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'email', 'code' => '123456',
        ])->assertStatus(202)->assertJsonMissing(['access_token']);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'totp', 'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_incomplete_unverified_totp_setup_is_not_required(): void
    {
        $user = $this->emailUser();
        $user->mfaMethods()->create([
            'method' => 'totp', 'is_enabled' => false, 'is_verified' => false,
            'pending_secret_encrypted' => Crypt::encryptString($this->totp->generateSecretKey(32)),
            'pending_expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJsonPath('required_methods', ['email'])
            ->assertJsonMissingPath('required_methods.1');
        $this->assertNotEmpty($response->json('mfa_challenge_token'));
    }

    public function test_default_requires_enabled_method_and_disabling_totp_falls_back_to_email(): void
    {
        [$user] = $this->totpUser();
        $user->update(['two_factor_enabled' => true]);
        $user->mfaMethods()->create(['method' => 'email', 'is_enabled' => true, 'is_verified' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/me/security/mfa/default-method', ['method' => 'email'])->assertOk();
        $this->postJson('/api/me/security/mfa/totp/disable', ['current_password' => 'password'])
            ->assertOk()->assertJsonPath('mfa.default_method', 'email');
        $this->assertNull(UserMfaMethod::where('user_id', $user->id)->where('method', 'totp')->value('secret_encrypted'));
        $this->assertSame(0, $user->mfaRecoveryCodes()->count());
    }

    public function test_recovery_code_is_single_use(): void
    {
        [$user] = $this->totpUser();
        $code = app(MfaService::class)->regenerateRecoveryCodes($user)[0];
        [$firstToken] = $this->loginChallenge($user);
        $this->failTotp($firstToken, 3);
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $firstToken, 'method' => 'recovery_code', 'code' => $code,
        ])->assertOk();

        [$secondToken] = $this->loginChallenge($user);
        $this->failTotp($secondToken, 3);
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $secondToken, 'method' => 'recovery_code', 'code' => $code,
        ])->assertUnprocessable();
    }

    private function totpUser(array $attributes = []): array
    {
        $user = User::factory()->create($attributes + ['password' => Hash::make('password')]);
        $secret = $this->totp->generateSecretKey(32);
        $user->mfaMethods()->create([
            'method' => 'totp', 'is_enabled' => true, 'is_verified' => true,
            'secret_encrypted' => Crypt::encryptString($secret),
        ]);
        UserMfaSetting::create(['user_id' => $user->id, 'is_enabled' => true, 'default_method' => 'totp']);

        return [$user, $secret];
    }

    private function emailUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + [
            'password' => Hash::make('password'),
            'two_factor_enabled' => true,
        ]);
        $user->mfaMethods()->create(['method' => 'email', 'is_enabled' => true, 'is_verified' => true]);
        UserMfaSetting::create(['user_id' => $user->id, 'is_enabled' => true, 'default_method' => 'email']);

        return $user;
    }

    private function bothMethodsUser(array $attributes = []): array
    {
        [$user, $secret] = $this->totpUser($attributes);
        $user->update(['two_factor_enabled' => true]);
        $user->mfaMethods()->create(['method' => 'email', 'is_enabled' => true, 'is_verified' => true]);

        return [$user, $secret];
    }

    private function loginChallenge(User $user): array
    {
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)
            ->assertJsonMissing(['access_token']);

        return [$response->json('mfa_challenge_token'), MfaChallenge::latest('id')->firstOrFail()];
    }

    private function failTotp(string $token, int $times = 1)
    {
        $response = null;
        for ($attempt = 0; $attempt < $times; $attempt++) {
            $response = $this->postJson('/api/auth/mfa/verify', [
                'challenge_token' => $token, 'method' => 'totp', 'code' => '000000',
            ])->assertUnprocessable();
        }

        return $response;
    }
}
