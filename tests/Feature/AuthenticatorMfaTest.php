<?php

namespace Tests\Feature;

use App\Models\MfaChallenge;
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

    public function test_invalid_totp_increments_attempts_and_expired_challenge_is_rejected(): void
    {
        [$user] = $this->totpUser();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('mfa_challenge_token');
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'totp', 'code' => '000000',
        ])->assertUnprocessable();
        $this->assertSame(1, MfaChallenge::firstOrFail()->attempts);

        MfaChallenge::firstOrFail()->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $token, 'method' => 'totp', 'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    }

    public function test_both_methods_are_available_and_legacy_email_mfa_still_completes_login(): void
    {
        [$user] = $this->totpUser();
        $user->update(['two_factor_enabled' => true]);
        $user->mfaMethods()->create(['method' => 'email', 'is_enabled' => true, 'is_verified' => true]);
        UserMfaSetting::updateOrCreate(['user_id' => $user->id], ['is_enabled' => true, 'default_method' => 'email']);

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(202)->assertJsonPath('available_methods', ['email', 'totp']);
        $challenge = MfaChallenge::firstOrFail();
        $challenge->update(['email_code_hash' => Hash::make('123456')]);

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $response->json('mfa_challenge_token'),
            'method' => 'email',
            'code' => '123456',
        ])->assertOk()->assertJsonStructure(['access_token']);
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
        $firstToken = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('mfa_challenge_token');
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $firstToken, 'method' => 'recovery_code', 'code' => $code,
        ])->assertOk();

        $secondToken = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('mfa_challenge_token');
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_token' => $secondToken, 'method' => 'recovery_code', 'code' => $code,
        ])->assertUnprocessable();
    }

    private function totpUser(): array
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $secret = $this->totp->generateSecretKey(32);
        $user->mfaMethods()->create([
            'method' => 'totp', 'is_enabled' => true, 'is_verified' => true,
            'secret_encrypted' => Crypt::encryptString($secret),
        ]);
        UserMfaSetting::create(['user_id' => $user->id, 'is_enabled' => true, 'default_method' => 'totp']);

        return [$user, $secret];
    }
}
