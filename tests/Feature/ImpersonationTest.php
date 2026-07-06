<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\ImpersonationSession;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ImpersonationTest extends TestCase
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
    }

    private function user(string $roleName, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $this->roles[$roleName]->id,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function clearAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * 1. Super Admin can start impersonation of an active non-Super-Admin user.
     */
    public function test_super_admin_can_start_impersonation_of_active_non_super_admin(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');

        Sanctum::actingAs($superAdmin);

        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['access_token', 'token_type', 'user']);
    }

    /**
     * 2-10. Other roles cannot start impersonation.
     */
    public function test_other_roles_cannot_start_impersonation(): void
    {
        $target = $this->user('author');
        $nonSuperAdminRoles = ['admin', 'editor', 'sub_editor', 'reviewer', 'publisher', 'copy_editor', 'proofreader'];

        foreach ($nonSuperAdminRoles as $roleName) {
            $caller = $this->user($roleName);
            Sanctum::actingAs($caller);

            $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
                'confirmed' => true
            ]);

            $response->assertStatus(403);
        }

        // Normal user (no role at all)
        $normalUser = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($normalUser);
        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $response->assertStatus(403);
    }

    /**
     * 11. Active impersonated session cannot start another impersonation.
     */
    public function test_active_impersonated_session_cannot_start_another_impersonation(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target1 = $this->user('editor');
        $target2 = $this->user('author');

        $response = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target1->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response->json('access_token');

        $this->clearAuth();

        // Authenticate with target1 impersonated session token
        $response2 = $this->withToken($token)->postJson("/api/admin/users/{$target2->id}/impersonate", [
            'confirmed' => true
        ]);

        $response2->assertStatus(400);
        $response2->assertJsonPath('message', 'Impersonated sessions cannot start impersonation.');
    }

    /**
     * 12. Super Admin cannot impersonate another Super Admin.
     */
    public function test_super_admin_cannot_impersonate_another_super_admin(): void
    {
        $superAdmin1 = $this->user('super_admin');
        $superAdmin2 = $this->user('super_admin');

        Sanctum::actingAs($superAdmin1);
        $response = $this->postJson("/api/admin/users/{$superAdmin2->id}/impersonate", [
            'confirmed' => true
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'This user cannot be impersonated.');
    }

    /**
     * 13. Super Admin cannot impersonate self.
     */
    public function test_super_admin_cannot_impersonate_self(): void
    {
        $superAdmin = $this->user('super_admin');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson("/api/admin/users/{$superAdmin->id}/impersonate", [
            'confirmed' => true
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'This user cannot be impersonated.');
    }

    /**
     * 14. Super Admin cannot impersonate inactive user.
     */
    public function test_super_admin_cannot_impersonate_inactive_user(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor', ['email_verified_at' => null]); // inactive

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'This user cannot be impersonated.');
    }

    /**
     * 15. Super Admin cannot impersonate blocked/suspended/deleted user.
     */
    public function test_super_admin_cannot_impersonate_deleted_user(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');
        $target->delete(); // soft deleted

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'This user cannot be impersonated.');
    }

    /**
     * 16. Nonexistent target returns safe not-found response.
     */
    public function test_nonexistent_target_returns_safe_not_found(): void
    {
        $superAdmin = $this->user('super_admin');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/admin/users/99999/impersonate', [
            'confirmed' => true
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'User not found.');
    }

    /**
     * 17. Start response does not contain original Super Admin token.
     */
    public function test_start_response_does_not_contain_original_token(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $response->assertOk();
        $this->assertNotEquals($superAdminToken, $response->json('access_token'));
        $response->assertJsonMissing(['original_token', 'super_admin_token']);
    }

    /**
     * 18. Start response does not expose session secrets, raw session IDs, password hash, reset fields, 2FA data, or internal paths.
     */
    public function test_start_response_excludes_sensitive_fields(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor', [
            'password' => 'somehash',
            'verification_code' => '123456',
            'two_factor_code' => '654321',
        ]);

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $response->assertOk();
        $response->assertJsonMissing([
            'password',
            'password_hash',
            'remember_token',
            'verification_code',
            'two_factor_code',
            'google_id',
            'internal_path',
        ]);
    }

    /**
     * 19. Temporary impersonation token/session is marked server-side as impersonation.
     */
    public function test_temporary_impersonation_token_is_marked_as_such(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $token = $response->json('access_token');
        $tokenModel = DB::table('personal_access_tokens')->where('token', hash('sha256', explode('|', $token)[1]))->first();

        $this->assertEquals('impersonation_token', $tokenModel->name);
    }

    /**
     * 20. Temporary session has expiry.
     */
    public function test_temporary_session_has_expiry(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $token = $response->json('access_token');
        $tokenModel = DB::table('personal_access_tokens')->where('token', hash('sha256', explode('|', $token)[1]))->first();

        $this->assertNotNull($tokenModel->expires_at);
        $this->assertTrue(now()->lt($tokenModel->expires_at));
    }

    /**
     * 21. Temporary session cannot access Super Admin-only API endpoints.
     */
    public function test_temporary_session_cannot_access_super_admin_only_endpoints(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response->json('access_token');

        $this->clearAuth();

        // Attempt user show (restricted to Super Admin) using target token
        $response2 = $this->withToken($token)->getJson("/api/admin/users/{$target->id}");

        $response2->assertStatus(403);
    }

    /**
     * 22. Temporary session cannot call impersonation start endpoint.
     */
    public function test_temporary_session_cannot_call_start_endpoint(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target1 = $this->user('editor');
        $target2 = $this->user('author');

        $response = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target1->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response->json('access_token');

        $this->clearAuth();

        $response2 = $this->withToken($token)->postJson("/api/admin/users/{$target2->id}/impersonate", [
            'confirmed' => true
        ]);

        $response2->assertStatus(400);
    }

    /**
     * 23. Stop endpoint rejects normal non-impersonated session.
     */
    public function test_stop_endpoint_rejects_normal_non_impersonated_session(): void
    {
        $editor = $this->user('editor');
        Sanctum::actingAs($editor);

        $response = $this->postJson('/api/admin/impersonation/stop');
        $response->assertStatus(400);
        $response->assertJsonPath('message', 'No active impersonation session found.');
    }

    /**
     * 24. Stop endpoint restores a fresh Super Admin session safely.
     */
    public function test_stop_endpoint_restores_fresh_super_admin(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response1 = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response1->json('access_token');

        $this->clearAuth();

        $response2 = $this->withToken($token)->postJson('/api/admin/impersonation/stop');

        $response2->assertOk();
        $response2->assertJsonStructure(['access_token', 'token_type', 'user']);
        $this->assertEquals($superAdmin->id, $response2->json('user.id'));
    }

    /**
     * 25. Stop invalidates temporary impersonated token/session.
     */
    public function test_stop_invalidates_temporary_impersonated_token(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response1 = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response1->json('access_token');

        $this->clearAuth();

        $response2 = $this->withToken($token)->postJson('/api/admin/impersonation/stop');
        $response2->assertOk();

        $this->clearAuth();

        // Target token must be invalid now
        $response3 = $this->withToken($token)->getJson('/api/me');
        $response3->assertStatus(401);
    }

    /**
     * 26. Expired impersonation session is rejected.
     */
    public function test_expired_impersonation_session_is_rejected(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response1 = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response1->json('access_token');

        // Travel to future to expire token
        $this->travel(31)->minutes();

        $this->clearAuth();

        $response2 = $this->withToken($token)->getJson('/api/me');
        $response2->assertStatus(401);
    }

    /**
     * 27. Deactivated target user ends/rejects impersonation session.
     */
    public function test_deactivated_target_user_rejects_impersonation_session(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response1 = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response1->json('access_token');

        // Deactivate target user
        $target->update(['email_verified_at' => null]);

        $this->clearAuth();

        $response2 = $this->withToken($token)->getJson('/api/me');
        $response2->assertStatus(401);
    }

    /**
     * 28. Start creates an impersonation audit record/event.
     */
    public function test_start_creates_impersonation_audit_record(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'impersonation_started',
            'user_id' => $superAdmin->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id
        ]);
    }

    /**
     * 29. Stop creates an impersonation audit record/event.
     */
    public function test_stop_creates_impersonation_audit_record(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response->json('access_token');

        $this->clearAuth();

        $this->withToken($token)->postJson('/api/admin/impersonation/stop');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'impersonation_stopped',
            'user_id' => $superAdmin->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id
        ]);
    }

    /**
     * 30. Audit entry includes original actor and target user IDs.
     */
    public function test_audit_entry_includes_original_and_target_ids(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $log = AuditLog::where('event', 'impersonation_started')->first();
        $this->assertEquals($superAdmin->id, $log->user_id);
        $this->assertEquals($target->id, $log->auditable_id);
    }

    /**
     * 31. Audit entry contains no plaintext token.
     */
    public function test_audit_entry_contains_no_plaintext_token(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response->json('access_token');

        $log = AuditLog::where('event', 'impersonation_started')->first();
        $serialized = json_encode($log->toArray());

        $this->assertStringNotContainsString($token, $serialized);
    }

    /**
     * 32. Audit includes timestamps and, where available, IP/user-agent.
     */
    public function test_audit_includes_timestamps_ip_user_agent(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('editor');

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);

        $log = AuditLog::where('event', 'impersonation_started')->first();
        $this->assertNotNull($log->created_at);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->user_agent);
    }

    /**
     * 33. Active impersonated session can retrieve safe status.
     */
    public function test_active_impersonated_session_can_retrieve_safe_status(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response->json('access_token');

        $this->clearAuth();

        $response2 = $this->withToken($token)->getJson('/api/admin/impersonation/status');

        $response2->assertOk();
        $response2->assertJsonPath('active', true);
        $response2->assertJsonStructure([
            'active',
            'impersonated_user' => ['id', 'name'],
            'started_at',
            'expires_at'
        ]);
    }

    /**
     * 34. Normal Super Admin session receives inactive/no-impersonation status.
     */
    public function test_normal_super_admin_receives_inactive_status(): void
    {
        $superAdmin = $this->user('super_admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/admin/impersonation/status');
        $response->assertOk();
        $response->assertJsonPath('active', false);
    }

    /**
     * 35. Status payload excludes original Super Admin token and internal session metadata.
     */
    public function test_status_payload_excludes_session_metadata_and_tokens(): void
    {
        $superAdmin = $this->user('super_admin');
        $superAdminToken = $superAdmin->createToken('auth_token')->plainTextToken;
        $target = $this->user('editor');

        $response = $this->withToken($superAdminToken)->postJson("/api/admin/users/{$target->id}/impersonate", [
            'confirmed' => true
        ]);
        $token = $response->json('access_token');

        $this->clearAuth();

        $response2 = $this->withToken($token)->getJson('/api/admin/impersonation/status');

        $response2->assertJsonMissing([
            'access_token',
            'token',
            'original_super_admin_id',
            'impersonation_token_id'
        ]);
    }
}
