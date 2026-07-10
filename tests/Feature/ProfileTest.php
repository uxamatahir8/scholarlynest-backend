<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\MediaUploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
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
            'name' => 'Dr. Alice',
            'email' => 'alice@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Test authenticated user can update profile name, but arbitrary profile-image URLs are rejected.
     */
    public function test_user_can_update_profile_successfully(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'name' => 'Dr. Alice Allison',
            'email' => 'alice.new@test.com', // Attempting to change email directly
            'profile_image' => 'https://scholarlynest.com/storage/uploads/avatar.png',
        ];

        $response = $this->putJson('/api/profile', $payload);

        $response->assertStatus(422);

        $response = $this->putJson('/api/profile', [
            'name' => 'Dr. Alice Allison',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.name', 'Dr. Alice Allison');
        $response->assertJsonPath('user.email', 'alice@test.com'); // Remains unchanged

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Dr. Alice Allison',
            'email' => 'alice@test.com', // Must not change
        ]);
    }

    public function test_profile_image_requires_owned_clean_profile_upload(): void
    {
        Sanctum::actingAs($this->user);

        $upload = MediaUploadSession::create([
            'user_id' => $this->user->id,
            'purpose' => 'profile_image',
            'original_filename' => 'avatar.png',
            'safe_display_filename' => 'avatar.png',
            'expected_size_bytes' => 1024,
            'declared_mime_type' => 'image/png',
            'disk' => 's3',
            's3_incoming_key' => 'incoming/avatar.png',
            's3_clean_key' => 'clean/profiles/avatar.png',
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_CLEAN,
            'expires_at' => now()->addHour(),
        ]);

        $this->putJson('/api/profile', [
            'name' => 'Dr. Alice',
            'profile_image_upload_id' => $upload->id,
        ])->assertOk()
            ->assertJsonMissing(['profile_image' => 'clean/profiles/avatar.png']);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'profile_image' => 'clean/profiles/avatar.png',
        ]);
    }

    public function test_self_profile_update_rejects_role_scope_and_permission_fields(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/profile', [
            'name' => 'Dr. Alice',
            'role_id' => 999,
            'permissions' => ['roles.manage'],
            'magazine_ids' => [1],
        ])->assertStatus(422);

        $this->assertSame($this->authorRole->id, $this->user->fresh()->role_id);
    }

    public function test_password_change_requires_verified_single_use_code(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/password/request-code')->assertOk();
        $code = $this->user->fresh()->password_change_code;

        $this->postJson('/api/password/change', [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(403);

        $this->postJson('/api/password/verify-code', ['code' => $code])->assertOk();

        $this->postJson('/api/password/change', [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertOk();

        $this->postJson('/api/password/change', [
            'code' => $code,
            'password' => 'AnotherPassw0rd!',
            'password_confirmation' => 'AnotherPassw0rd!',
        ])->assertStatus(403);
    }

    public function test_password_change_verification_is_rate_limited(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/password/request-code')->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/password/verify-code', ['code' => '000000'])->assertStatus(400);
        }

        $this->postJson('/api/password/verify-code', ['code' => $this->user->fresh()->password_change_code])
            ->assertStatus(429);
    }

    /**
     * Test complete double-verification email update flow.
     */
    public function test_user_can_change_email_via_double_verification(): void
    {
        Sanctum::actingAs($this->user);

        // Step 1: Request code to current email
        $response = $this->postJson('/api/profile/email/request-current-code');
        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertNotNull($this->user->email_change_code);
        $this->assertFalse($this->user->current_email_verified);

        // Step 2: Verify current email code
        $response = $this->postJson('/api/profile/email/verify-current-code', [
            'code' => $this->user->email_change_code
        ]);
        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertNull($this->user->email_change_code);
        $this->assertTrue($this->user->current_email_verified);

        // Step 3: Request code for the new email address
        $response = $this->postJson('/api/profile/email/request-new-code', [
            'email' => 'alice.new@test.com'
        ]);
        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertEquals('alice.new@test.com', $this->user->pending_email);
        $this->assertNotNull($this->user->new_email_verification_code);

        // Step 4: Verify code sent to the new email address
        $response = $this->postJson('/api/profile/email/verify-new-code', [
            'code' => $this->user->new_email_verification_code
        ]);
        $response->assertStatus(200);

        $this->user->refresh();
        $this->assertEquals('alice.new@test.com', $this->user->email);
        $this->assertNull($this->user->pending_email);
        $this->assertNull($this->user->new_email_verification_code);
        $this->assertFalse($this->user->current_email_verified);
    }

    /**
     * Test user cannot proceed to step 2/3 without verifying step 1.
     */
    public function test_user_cannot_request_new_code_without_verifying_current_email(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/profile/email/request-new-code', [
            'email' => 'alice.new@test.com'
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test unique constraints on new email address request.
     */
    public function test_user_cannot_request_new_code_with_existing_email(): void
    {
        User::create([
            'name' => 'Bob',
            'email' => 'bob@test.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role_id' => $this->authorRole->id,
        ]);

        Sanctum::actingAs($this->user);

        // Authenticate current email first
        $this->user->update(['current_email_verified' => true]);

        // Attempt requesting code for Bob's email
        $response = $this->postJson('/api/profile/email/request-new-code', [
            'email' => 'bob@test.com'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }
}
