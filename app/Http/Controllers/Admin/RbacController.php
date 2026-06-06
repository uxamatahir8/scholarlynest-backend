<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RbacController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get all roles loaded with their current permissions.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::with('permissions')->get();
        return response()->json($roles);
    }

    /**
     * Get all permissions in the system.
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::all();
        return response()->json($permissions);
    }

    /**
     * Create a new role.
     */
    public function storeRole(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:roles,name|max:255',
            'display_name' => 'required|string|max:255',
        ]);

        $role = Role::create([
            'name' => strtolower(Str::slug($request->name)),
            'display_name' => $request->display_name,
            'is_system' => false,
        ]);

        return response()->json($role->load('permissions'), 201);
    }

    /**
     * Delete a custom role. System roles are protected.
     */
    public function deleteRole(int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted.'
            ], 400);
        }

        // Check if any users are assigned to this role
        $assignedUsersCount = User::where('role_id', $role->id)->count();
        if ($assignedUsersCount > 0) {
            return response()->json([
                'message' => 'This role is assigned to active users. Reassign users before deleting.'
            ], 400);
        }

        // Dissociate permissions and delete the role
        $role->permissions()->detach();
        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully.'
        ]);
    }

    /**
     * Sync permissions to a specific role.
     */
    public function syncRolePermissions(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::findOrFail($id);
        $permissionIds = Permission::whereIn('name', $request->permissions)->pluck('id');
        $role->permissions()->sync($permissionIds);

        return response()->json($role->load('permissions'));
    }

    /**
     * Get all system users along with their role.
     */
    public function users(): JsonResponse
    {
        $users = User::with('role')->get();
        return response()->json($users);
    }

    /**
     * Update a user's single role.
     */
    public function updateUserRole(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $user = User::findOrFail($id);
        
        // Prevent users from de-roling themselves from super_admin
        if ($user->id === $request->user()->id && $user->role?->name === 'super_admin' && intval($request->role_id) !== $user->role_id) {
            return response()->json([
                'message' => 'You cannot remove your own super_admin role.'
            ], 400);
        }

        $user->role_id = $request->role_id;
        $user->save();

        return response()->json($user->load('role'));
    }

    /**
     * Create a user with a specified role and without a password. Dispatches a welcome reset email.
     */
    public function storeUser(Request $request): JsonResponse
    {
        // Only super_admin is allowed to create administrative users
        if ($request->user()->role?->name !== 'super_admin') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role_id' => 'required|exists:roles,id',
        ]);

        $randomPassword = Str::random(32);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($randomPassword),
            'email_verified_at' => now(),
            'role_id' => $request->role_id,
        ]);

        // Generate reset code/token for password creation
        $code = strval(mt_rand(100000, 999999));
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => $code,
                'created_at' => now(),
            ]
        );

        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/');
        $createPasswordLink = "{$frontendUrl}/reset-password?email=" . urlencode($user->email) . "&code=" . urlencode($code);

        $this->sendWelcomeHtmlEmail($user->email, $user->name, $createPasswordLink);

        return response()->json($user->load('role'), 201);
    }

    /**
     * Get the default registration role.
     */
    public function getRegistrationRole(): JsonResponse
    {
        $roleName = Setting::where('key', 'default_registration_role')->value('value') ?? 'author';
        $role = Role::where('name', $roleName)->first();

        return response()->json([
            'default_registration_role' => $roleName,
            'role' => $role
        ]);
    }

    /**
     * Update the default registration role setting.
     */
    public function updateRegistrationRole(Request $request): JsonResponse
    {
        $request->validate([
            'default_registration_role' => 'required|string|exists:roles,name',
        ]);

        Setting::updateOrCreate(
            ['key' => 'default_registration_role'],
            ['value' => $request->default_registration_role]
        );

        return response()->json([
            'message' => 'Default registration role updated successfully.'
        ]);
    }

    /**
     * Send welcome HTML email with optional password creation link.
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
}
