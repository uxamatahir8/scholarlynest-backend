<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Notifications\NotificationEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImpersonationController extends Controller
{
    public function __construct(private NotificationEventRecorder $notificationEvents) {}

    /**
     * Start user impersonation.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        // Impersonated session cannot start another impersonation
        $currentAccessToken = $request->user() ? $request->user()->currentAccessToken() : null;
        if ($currentAccessToken && $currentAccessToken->name === 'impersonation_token') {
            return response()->json(['message' => 'Impersonated sessions cannot start impersonation.'], 400);
        }

        // Assert caller is Super Admin
        if (! $request->user() || ! $request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        // Require explicit confirmation flag
        if (! $request->input('confirmed')) {
            return response()->json(['message' => 'Impersonation request not confirmed.'], 400);
        }

        // Find target user
        $targetUser = User::withTrashed()->find($id);
        if (! $targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Target cannot be soft-deleted, must be active (email verified)
        if ($targetUser->trashed() || ! $targetUser->email_verified_at) {
            return response()->json(['message' => 'This user cannot be impersonated.'], 400);
        }

        // Cannot impersonate another Super Admin
        if ($targetUser->hasRole('super_admin')) {
            return response()->json(['message' => 'This user cannot be impersonated.'], 400);
        }

        // Cannot impersonate self
        if ($targetUser->id === $request->user()->id) {
            return response()->json(['message' => 'This user cannot be impersonated.'], 400);
        }

        $expiry = now()->addMinutes(30);

        $tokenInstance = DB::transaction(function () use ($request, $targetUser, $expiry) {
            $tokenInstance = $targetUser->createToken('impersonation_token', ['*'], $expiry);
            ImpersonationSession::create([
                'original_super_admin_id' => $request->user()->id,
                'impersonated_user_id' => $targetUser->id,
                'impersonation_token_id' => $tokenInstance->accessToken->id,
                'started_at' => now(),
                'expires_at' => $expiry,
                'status' => 'active',
                'started_ip' => $request->ip(),
                'started_user_agent' => $request->userAgent(),
            ]);
            AuditLog::create([
                'event' => 'impersonation_started',
                'user_id' => $request->user()->id,
                'auditable_type' => User::class,
                'auditable_id' => $targetUser->id,
                'old_values' => ['super_admin_id' => $request->user()->id],
                'new_values' => ['impersonated_user_id' => $targetUser->id],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $this->notificationEvents->record(
                'account.impersonation_started', null, $request->user(),
                ['recipient_user_ids' => [$request->user()->id, $targetUser->id], 'recipient_privacy_variant' => 'account'], 'user', $targetUser->id,
                deduplicationKey: "impersonation-session:{$tokenInstance->accessToken->id}:started"
            );

            return $tokenInstance;
        });

        return response()->json([
            'access_token' => $tokenInstance->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->authUserPayload($targetUser),
        ]);
    }

    /**
     * Get active impersonation status.
     */
    public function status(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();

        if ($accessToken && $accessToken->name === 'impersonation_token') {
            $session = ImpersonationSession::where('impersonation_token_id', $accessToken->id)
                ->where('status', 'active')
                ->first();

            if ($session && now()->lt($session->expires_at)) {
                return response()->json([
                    'active' => true,
                    'impersonated_user' => [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                    ],
                    'started_at' => $session->started_at?->toIso8601String(),
                    'expires_at' => $session->expires_at?->toIso8601String(),
                ]);
            }
        }

        return response()->json(['active' => false]);
    }

    /**
     * Stop user impersonation and restore Super Admin.
     */
    public function stop(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();

        if (! $accessToken || $accessToken->name !== 'impersonation_token') {
            return response()->json(['message' => 'No active impersonation session found.'], 400);
        }

        $session = ImpersonationSession::where('impersonation_token_id', $accessToken->id)
            ->where('status', 'active')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'No active impersonation session found.'], 400);
        }

        // Retrieve original Super Admin
        $superAdmin = User::find($session->original_super_admin_id);
        if (! $superAdmin || ! $superAdmin->email_verified_at) {
            return response()->json(['message' => 'Original Super Admin session could not be restored.'], 400);
        }

        $newTokenInstance = DB::transaction(function () use ($request, $session, $superAdmin, $accessToken) {
            $session->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stopped_ip' => $request->ip(),
                'stopped_user_agent' => $request->userAgent(),
            ]);
            AuditLog::create([
                'event' => 'impersonation_stopped',
                'user_id' => $superAdmin->id,
                'auditable_type' => User::class,
                'auditable_id' => $request->user()->id,
                'old_values' => ['impersonated_user_id' => $request->user()->id],
                'new_values' => ['super_admin_id' => $superAdmin->id],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $this->notificationEvents->record(
                'account.impersonation_stopped', null, $superAdmin,
                ['recipient_user_ids' => [$superAdmin->id, $request->user()->id], 'recipient_privacy_variant' => 'account'], 'user', $request->user()->id,
                deduplicationKey: "impersonation-session:{$session->id}:stopped"
            );
            $accessToken->delete();

            return $superAdmin->createToken('auth_token');
        });

        return response()->json([
            'access_token' => $newTokenInstance->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->authUserPayload($superAdmin),
        ]);
    }

    /**
     * Build the standard user payload matching AuthController requirements.
     */
    private function authUserPayload(User $user): array
    {
        $user->loadMissing('role.permissions');
        $role = $user->role;
        $permissionNames = $role?->permissions
            ? $role->permissions->pluck('name')->values()->all()
            : [];

        return [
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
    }

    private function capabilityPayload(array $permissionNames, User $user): array
    {
        $capabilities = [];
        foreach ($permissionNames as $pName) {
            $capabilities[$pName] = true;
        }

        return $capabilities;
    }
}
