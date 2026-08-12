<?php

namespace Tests\Feature;

use App\Models\ImpersonationSession;
use App\Models\Magazine;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'author', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'] as $roleName) {
            $this->roles[$roleName] = Role::create([
                'name' => $roleName,
                'display_name' => Str::headline($roleName),
                'is_system' => true,
            ]);
        }

        Setting::create(['key' => 'default_registration_role', 'value' => 'author']);
    }

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => $this->roles[$roleName]->id]);
    }

    public function test_super_admin_can_retrieve_registration_settings(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->getJson('/api/admin/user-management/registration-settings');

        $response->assertOk()
            ->assertJsonPath('data.registration_enabled', true)
            ->assertJsonPath('data.default_role.name', 'author')
            ->assertJsonPath('data.email_verification_required', true)
            ->assertJsonPath('data.registration_notice', 'Create an author account to submit manuscripts.');
    }

    public function test_non_super_admin_roles_cannot_retrieve_registration_settings(): void
    {
        foreach (['admin', 'author', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'] as $roleName) {
            Sanctum::actingAs($this->user($roleName));

            $this->getJson('/api/admin/user-management/registration-settings')->assertForbidden();
            $this->getJson('/api/admin/user-management/registration-role-options')->assertForbidden();
        }
    }

    public function test_impersonated_session_cannot_retrieve_registration_settings(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('author');
        $tokenResult = $target->createToken('impersonation_token');

        ImpersonationSession::create([
            'original_super_admin_id' => $superAdmin->id,
            'impersonated_user_id' => $target->id,
            'impersonation_token_id' => $tokenResult->accessToken->id,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'stopped_at' => null,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $tokenResult->plainTextToken)
            ->getJson('/api/admin/user-management/registration-settings')
            ->assertForbidden();
    }

    public function test_super_admin_can_update_valid_registration_settings(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->patchJson('/api/admin/user-management/registration-settings', [
            'registration_enabled' => false,
            'default_role_id' => $this->roles['author']->id,
            'registration_notice' => 'Author registration is temporarily paused.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.registration_enabled', false)
            ->assertJsonPath('data.default_role.name', 'author')
            ->assertJsonPath('data.registration_notice', 'Author registration is temporarily paused.');

        $this->assertDatabaseHas('settings', ['key' => 'registration_enabled', 'value' => '0']);
        $this->assertDatabaseHas('settings', ['key' => 'default_registration_role', 'value' => 'author']);
    }

    public function test_invalid_boolean_setting_is_rejected_safely(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $this->patchJson('/api/admin/user-management/registration-settings', [
            'registration_enabled' => 'definitely',
            'default_role_id' => $this->roles['author']->id,
            'registration_notice' => '',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('registration_enabled');
    }

    public function test_invalid_default_role_id_is_rejected_safely(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $this->patchJson('/api/admin/user-management/registration-settings', [
            'registration_enabled' => true,
            'default_role_id' => 999999,
            'registration_notice' => '',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('default_role_id');
    }

    public function test_privileged_roles_cannot_be_chosen_as_default_registration_role(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        foreach (['super_admin', 'admin', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'] as $roleName) {
            $this->patchJson('/api/admin/user-management/registration-settings', [
                'registration_enabled' => true,
                'default_role_id' => $this->roles[$roleName]->id,
                'registration_notice' => '',
            ])->assertStatus(422)
                ->assertJsonValidationErrors('default_role_id');

            $this->postJson('/api/admin/rbac/settings/registration-role', [
                'default_registration_role' => $roleName,
            ])->assertStatus(422)
                ->assertJsonValidationErrors('default_registration_role');
        }
    }

    public function test_registration_role_options_are_minimal_and_safe(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $response = $this->getJson('/api/admin/user-management/registration-role-options')->assertOk();

        $this->assertSame(['author'], collect($response->json('data'))->pluck('name')->all());
        $roleRow = $response->json('data.0');
        foreach (['id', 'name', 'display_name'] as $key) {
            $this->assertArrayHasKey($key, $roleRow);
        }
        foreach (['permissions', 'users', 'password', 'tokens', 'pivot', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayNotHasKey($key, $roleRow);
        }
    }

    public function test_settings_response_excludes_sensitive_data(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        $data = $this->getJson('/api/admin/user-management/registration-settings')
            ->assertOk()
            ->json('data');

        foreach (['tokens', 'secrets', 'smtp', 'mail_password', 'env', 'permissions', 'users', 'password', 'path'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        foreach (['permissions', 'users', 'tokens', 'pivot'] as $key) {
            $this->assertArrayNotHasKey($key, $data['default_role']);
        }
    }

    public function test_public_registration_is_blocked_when_disabled(): void
    {
        Setting::updateOrCreate(['key' => 'registration_enabled'], ['value' => '0']);

        $this->postJson('/api/register', [
            'name' => 'Blocked Author',
            'email' => 'blocked.author@example.com',
            'university_name' => 'Scholarly University',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Registration is currently closed.');

        $this->assertDatabaseMissing('users', ['email' => 'blocked.author@example.com']);
    }

    public function test_public_registration_cannot_self_assign_privileged_roles(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Safe Author',
            'email' => 'safe.author@example.com',
            'university_name' => 'Scholarly University',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['super_admin']->id,
            'role' => 'super_admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'verification_required');

        $user = User::where('email', 'safe.author@example.com')->firstOrFail();
        $this->assertSame($this->roles['author']->id, $user->role_id);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->verification_code);
    }

    public function test_public_registration_falls_back_to_author_when_setting_is_unsafe(): void
    {
        Setting::updateOrCreate(['key' => 'default_registration_role'], ['value' => 'editor']);

        $this->postJson('/api/register', [
            'name' => 'Fallback Author',
            'email' => 'fallback.author@example.com',
            'university_name' => 'Scholarly University',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertCreated();

        $user = User::where('email', 'fallback.author@example.com')->firstOrFail();
        $this->assertSame($this->roles['author']->id, $user->role_id);
    }

    public function test_existing_create_user_role_flow_is_unaffected(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $magazine = Magazine::create([
            'title' => 'Registration Flow Magazine',
            'slug' => 'registration-flow-magazine',
            'description' => 'Registration flow test magazine',
        ]);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'New Editor',
            'email' => 'new.editor@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role_id' => $this->roles['editor']->id,
            'university_name' => 'Scholarly University',
            'status' => 'active',
            'magazine_ids' => [$magazine->id],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'new.editor@example.com',
            'role_id' => $this->roles['editor']->id,
        ]);
    }
}
