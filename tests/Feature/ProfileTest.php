<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
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
     * Test authenticated user can update profile name and image, but email is NOT updated.
     */
    public function test_user_can_update_profile_successfully(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'name' => 'Dr. Alice Allison',
            'email' => 'alice.new@test.com', // Attempting to change email directly
            'profile_image' => 'https://scholarlynest.com/storage/uploads/avatar.png'
        ];

        $response = $this->putJson('/api/profile', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('user.name', 'Dr. Alice Allison');
        $response->assertJsonPath('user.email', 'alice@test.com'); // Remains unchanged
        $response->assertJsonPath('user.profile_image', 'https://scholarlynest.com/storage/uploads/avatar.png');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Dr. Alice Allison',
            'email' => 'alice@test.com', // Must not change
            'profile_image' => 'https://scholarlynest.com/storage/uploads/avatar.png'
        ]);
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
