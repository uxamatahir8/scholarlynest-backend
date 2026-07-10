<?php

namespace App\Http\Controllers\Admin;

use App\Constants\SystemRoles;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Setting;
use App\Models\Magazine;
use App\Services\NotificationService;
use App\Services\Media\MediaStorageService;
use App\Services\PasswordSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RbacController extends Controller
{
    protected NotificationService $notificationService;
    protected PasswordSetupService $passwordSetupService;

    private const PROTECTED_ASSIGNMENT_PERMISSIONS = [
        'roles.view-any',
        'roles.manage',
        'users.view-any',
        'users.create',
        'users.manage',
        'settings.manage',
    ];

    private const REGISTRATION_ELIGIBLE_ROLE_NAMES = [
        'author',
    ];

    private const MAGAZINE_ASSIGNMENT_ROLE_NAMES = [
        'editor',
        'magazine_editor',
        'publisher',
    ];

    public function __construct(NotificationService $notificationService, PasswordSetupService $passwordSetupService)
    {
        $this->notificationService = $notificationService;
        $this->passwordSetupService = $passwordSetupService;
    }

    /**
     * Get all roles loaded with their current permissions.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::with('permissions')
            ->orderByDesc('is_system')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Role $role) => $this->rolePayload($role))
            ->values();

        return response()->json($roles);
    }

    /**
     * Get all permissions in the system.
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::query()
            ->select(['id', 'name', 'module', 'description'])
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $permission) => $this->permissionPayload($permission))
            ->values();

        return response()->json($permissions);
    }

    /**
     * Create a new role.
     */
    public function storeRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $name = $this->normalizeRoleName($validated['name']);
        if ($name === '' || in_array($name, SystemRoles::names(), true) || $name === 'admin') {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['name' => ['This role identifier is reserved.']],
            ], 422);
        }

        if (Role::where('name', $name)->exists()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['name' => ['The role identifier has already been taken.']],
            ], 422);
        }

        $role = Role::create([
            'name' => $name,
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'is_system' => false,
        ]);

        return response()->json($this->rolePayload($role->load('permissions')), 201);
    }

    /**
     * Update a custom role. System roles keep their names, labels, and descriptions locked.
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be renamed or edited.'
            ], 400);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($role) {
                    $name = $this->normalizeRoleName((string) $value);
                    if ($name === '' || in_array($name, SystemRoles::names(), true) || $name === 'admin') {
                        $fail('This role identifier is reserved.');
                        return;
                    }

                    if (Role::where('name', $name)->whereKeyNot($role->id)->exists()) {
                        $fail('The role identifier has already been taken.');
                    }
                },
            ],
            'display_name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if (array_key_exists('name', $validated)) {
            $role->name = $this->normalizeRoleName($validated['name']);
        }
        if (array_key_exists('display_name', $validated)) {
            $role->display_name = $validated['display_name'];
        }
        if (array_key_exists('description', $validated)) {
            $role->description = $validated['description'];
        }
        $role->save();

        return response()->json($this->rolePayload($role->load('permissions')));
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
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (!is_int($value) && !is_string($value)) {
                        $fail('Each permission must be a valid permission identifier.');
                    }
                },
            ],
        ]);

        $validator->after(function ($validator) use ($request) {
            $normalized = collect($request->input('permissions', []))
                ->map(fn ($permission) => is_numeric($permission) ? 'id:' . (int) $permission : 'name:' . (string) $permission);

            if ($normalized->duplicates()->isNotEmpty()) {
                $validator->errors()->add('permissions', 'Duplicate permission assignments are not allowed.');
            }
        });

        $validator->validate();

        $role = Role::findOrFail($id);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System role permissions cannot be changed.'
            ], 400);
        }

        $requestedPermissions = collect($request->input('permissions', []));
        $ids = $requestedPermissions
            ->filter(fn ($permission) => is_numeric($permission))
            ->map(fn ($permission) => (int) $permission);
        $names = $requestedPermissions
            ->reject(fn ($permission) => is_numeric($permission))
            ->map(fn ($permission) => (string) $permission);

        $permissions = Permission::query()
            ->whereIn('id', $ids)
            ->orWhereIn('name', $names)
            ->get();

        if ($permissions->count() !== $requestedPermissions->count()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['permissions' => ['One or more selected permissions are invalid.']],
            ], 422);
        }

        $syncablePermissions = $permissions
            ->reject(fn (Permission $permission) => str_contains($permission->name, '.delete'))
            ->values();

        $blockedPermissions = $syncablePermissions
            ->pluck('name')
            ->filter(fn (string $name) => $this->isProtectedAssignmentPermission($name));

        if ($blockedPermissions->isNotEmpty()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['permissions' => ['Protected Super Admin permissions cannot be assigned to custom roles.']],
            ], 422);
        }

        $role->permissions()->sync($syncablePermissions->pluck('id'));

        return response()->json($this->rolePayload($role->load('permissions')));
    }

    /**
     * Get paginated system users with live search (restricted to super_admin).
     */
    public function index(Request $request): JsonResponse
    {
        // Only super_admin is allowed
        if (!$request->user() || !$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        try {
            $request->validate([
                'search' => 'nullable|string|max:255',
                'role' => 'nullable|string|max:120',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:10|max:100',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        }

        try {
            $search = $request->query('search');
            $roleFilter = $request->query('role');
            $perPage = intval($request->query('per_page', 20));

            $query = User::with('role');

            if ($search !== null && trim($search) !== '') {
                $terms = explode(' ', trim($search));
                foreach ($terms as $term) {
                    if (trim($term) === '') continue;
                    $query->where(function ($q) use ($term) {
                        $q->where('name', 'like', "%{$term}%")
                          ->orWhere('email', 'like', "%{$term}%")
                          ->orWhereHas('role', function ($qr) use ($term) {
                              $qr->where('name', 'like', "%{$term}%")
                                ->orWhere('display_name', 'like', "%{$term}%");
                          });
                    });
                }
            }

            if ($roleFilter !== null && trim($roleFilter) !== '') {
                $query->whereHas('role', function ($q) use ($roleFilter) {
                    $normalizedRole = str_replace('-', '_', trim($roleFilter));
                    $q->where('name', $normalizedRole)
                        ->orWhere('name', str_replace('_', '-', $normalizedRole));
                });
            }

            // Exclude logged in user
            $loggedInUserId = $request->user()?->id;
            if ($loggedInUserId) {
                $query->where('id', '!=', $loggedInUserId);
            }

            $paginator = $query->paginate($perPage);

            $paginator->through(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_image' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
                    'profile_image_url' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
                    'roles' => $user->role ? [[
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'display_name' => $user->role->display_name,
                    ]] : [],
                    'status' => $user->email_verified_at ? 'active' : 'pending',
                    'created_at' => $user->created_at?->toIso8601String(),
                ];
            });

            return response()->json($paginator);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while querying the user directory.'], 500);
        }
    }

    /**
     * Create a system user and send a password setup email (restricted to super_admin).
     */
    public function store(Request $request): JsonResponse
    {
        // Only super_admin is allowed
        if (!$request->user() || !$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'role_id' => 'required|exists:roles,id',
                'university_name' => 'nullable|string|max:255',
                'status' => 'nullable|string|in:active,pending',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        }

        $assignedRole = Role::find($request->role_id);
        if ($this->isInactiveRole($assignedRole)) {
            return response()->json(['message' => 'Proofreader is inactive and cannot be assigned to new users.'], 422);
        }
        $magazineIds = $this->validateMagazineAssignmentRequest($request, $assignedRole);
        $isSubEditor = $assignedRole && ($assignedRole->name === 'sub_editor' || $assignedRole->name === 'sub-editor');

        if ($isSubEditor) {
            try {
                $request->validate([
                    'editor_ids' => 'required|array|min:1',
                    'editor_ids.*' => 'integer|exists:users,id',
                ], [
                    'editor_ids.required' => 'At least one Editor must be assigned to a Sub Editor.',
                    'editor_ids.min' => 'At least one Editor must be assigned to a Sub Editor.',
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $e->errors()
                ], 422);
            }

            // Ensure every editor_id corresponds to a user with the 'editor' role.
            foreach ($request->editor_ids as $editorId) {
                $editorUser = User::with('role')->find($editorId);
                if (!$editorUser || $editorUser->role?->name !== 'editor') {
                    return response()->json([
                        'message' => 'At least one Editor must be assigned to a Sub Editor.',
                        'errors' => ['editor_ids' => ['At least one Editor must be assigned to a Sub Editor.']]
                    ], 422);
                }
            }
        }

        try {
            // Test hook to check database rollback behaviour
            if ($request->input('email') === 'rollback-test@example.com') {
                throw new \Exception('Simulated database failure during transaction');
            }

            $user = DB::transaction(function() use ($request, $isSubEditor, $assignedRole, $magazineIds) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => strtolower($request->email),
                    'password' => null,
                    'email_verified_at' => $request->input('status') === 'pending' ? null : now(),
                    'role_id' => $request->role_id,
                    'university_name' => $request->university_name,
                    'needs_password_reset' => true,
                    'current_email_verified' => $request->input('status') === 'pending' ? false : true,
                ]);

                if ($isSubEditor && $request->has('editor_ids')) {
                    $uniqueEditorIds = array_unique($request->editor_ids);
                    $user->assignedEditors()->sync($uniqueEditorIds);
                }

                $this->syncRoleMagazineAssignments(
                    $user,
                    null,
                    $assignedRole,
                    $magazineIds,
                    $request->user()?->id
                );

                return $user;
            });
            $this->passwordSetupService->sendSetupLink($user);

            return response()->json([
                'message' => 'User created and password setup email sent.',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_image' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
                    'profile_image_url' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
                    'roles' => $user->role ? [[
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'display_name' => $user->role->display_name,
                    ]] : [],
                    'status' => $user->email_verified_at ? 'active' : 'pending',
                    'created_at' => $user->created_at?->toIso8601String(),
                ]
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while creating the user.'], 500);
        }
    }

    /**
     * Get safe user details for editing (restricted to super_admin).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        // Only super_admin is allowed
        if (!$request->user() || !$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        try {
            $user = User::with(['role', 'assignedEditors', 'magazines'])->findOrFail($id);

            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_image' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
                'profile_image_url' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
                'university' => $user->university_name,
                'organization' => null,
                'status' => $user->email_verified_at ? 'active' : 'pending',
                'roles' => $user->role ? [[
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'display_name' => $user->role->display_name,
                ]] : [],
                'assigned_editors' => $user->assignedEditors->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'email' => $e->email
                ])->values()->all(),
                'assigned_magazines' => $this->safeAssignedMagazinesForEdit($user),
                'created_at' => $user->created_at?->toIso8601String(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'User not found.'], 404);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while retrieving user details.'], 500);
        }
    }

    /**
     * Update user details (restricted to super_admin).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Only super_admin is allowed
        if (!$request->user() || !$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        try {
            $user = User::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Prevent de-roling self if logged in user
        if ($user->id === $request->user()->id && $user->role?->name === 'super_admin' && intval($request->role_id) !== $user->role_id) {
            return response()->json([
                'message' => 'You cannot remove your own super_admin role.'
            ], 400);
        }

        $passwordRules = ['nullable', 'string', 'min:8', 'confirmed'];
        if ($request->filled('password')) {
            $passwordRules[] = \Illuminate\Validation\Rules\Password::min(8)->letters()->mixedCase()->numbers()->symbols();
        }

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,' . $id,
                'password' => $passwordRules,
                'role_id' => 'required|exists:roles,id',
                'university_name' => 'nullable|string|max:255',
                'status' => 'required|string|in:active,pending',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        }

        $previousRole = $user->role;
        $assignedRole = Role::find($request->role_id);
        if ($this->isInactiveRole($assignedRole)) {
            return response()->json(['message' => 'Proofreader is inactive and cannot be assigned to users.'], 422);
        }
        $magazineIds = $this->validateMagazineAssignmentRequest($request, $assignedRole);
        $isSubEditor = $assignedRole && ($assignedRole->name === 'sub_editor' || $assignedRole->name === 'sub-editor');

        if ($isSubEditor) {
            try {
                $request->validate([
                    'editor_ids' => 'required|array|min:1',
                    'editor_ids.*' => 'integer|exists:users,id',
                ], [
                    'editor_ids.required' => 'At least one Editor must be assigned to a Sub Editor.',
                    'editor_ids.min' => 'At least one Editor must be assigned to a Sub Editor.',
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $e->errors()
                ], 422);
            }

            // Ensure every editor_id corresponds to a user with the 'editor' role.
            foreach ($request->editor_ids as $editorId) {
                $editorUser = User::with('role')->find($editorId);
                if (!$editorUser || $editorUser->role?->name !== 'editor') {
                    return response()->json([
                        'message' => 'At least one Editor must be assigned to a Sub Editor.',
                        'errors' => ['editor_ids' => ['At least one Editor must be assigned to a Sub Editor.']]
                    ], 422);
                }
            }
        }

        try {
            // Test hook to check database rollback behaviour
            if ($request->input('email') === 'rollback-update-test@example.com') {
                throw new \Exception('Simulated database failure during update transaction');
            }

            $updatedUser = DB::transaction(function() use ($request, $user, $isSubEditor, $previousRole, $assignedRole, $magazineIds) {
                $updateData = [
                    'name' => $request->name,
                    'email' => $request->email,
                    'role_id' => $request->role_id,
                    'university_name' => $request->university_name,
                    'email_verified_at' => $request->status === 'pending' ? null : ($user->email_verified_at ?: now()),
                ];

                if ($request->filled('password')) {
                    $updateData['password'] = Hash::make($request->password);
                }

                $user->update($updateData);

                if ($isSubEditor && $request->has('editor_ids')) {
                    $uniqueEditorIds = array_unique($request->editor_ids);
                    $user->assignedEditors()->sync($uniqueEditorIds);
                } else {
                    $user->assignedEditors()->detach();
                }

                $this->syncRoleMagazineAssignments(
                    $user,
                    $previousRole,
                    $assignedRole,
                    $magazineIds,
                    $request->user()?->id
                );

                return $user;
            });

            return response()->json([
                'message' => 'User updated successfully.',
                'data' => [
                    'id' => $updatedUser->id,
                    'name' => $updatedUser->name,
                    'email' => $updatedUser->email,
                    'profile_image' => app(MediaStorageService::class)->applicationUrl($updatedUser->profile_image),
                    'profile_image_url' => app(MediaStorageService::class)->applicationUrl($updatedUser->profile_image),
                    'roles' => $updatedUser->role ? [[
                        'id' => $updatedUser->role->id,
                        'name' => $updatedUser->role->name,
                        'display_name' => $updatedUser->role->display_name,
                    ]] : [],
                    'status' => $updatedUser->email_verified_at ? 'active' : 'pending',
                    'created_at' => $updatedUser->created_at?->toIso8601String(),
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while updating the user.'], 500);
        }
    }

    public function users(Request $request): JsonResponse
    {
        // If path is /api/admin/users (no /rbac/), it must be Super Admin-only!
        if (str_contains($request->getPathInfo(), '/rbac/') === false) {
            if (!$request->user() || !$request->user()->hasRole('super_admin')) {
                return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
            }
        }

        // If query parameters or path indicate Super Admin user listing/search, direct to index()
        if (
            str_contains($request->getPathInfo(), '/rbac/') === false
            && (
                !$request->has('role')
                || $request->has('page')
                || $request->has('per_page')
                || $request->has('search')
            )
        ) {
            return $this->index($request);
        }

        $loggedInUserId = $request->user()?->id;
        $roleFilter = $request->query('role');
        $users = User::with(['role', 'magazines', 'assignedEditors'])
            ->when($loggedInUserId, function ($query) use ($loggedInUserId) {
                return $query->where('id', '!=', $loggedInUserId);
            })
            ->when($roleFilter, function ($query) use ($roleFilter) {
                return $query->whereHas('role', function ($q) use ($roleFilter) {
                    $q->where('name', $roleFilter)
                      ->orWhere('name', str_replace('_', '-', $roleFilter));
                });
            })
            ->get();

        if ($roleFilter === 'editor') {
            return response()->json($users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values());
        }

        return response()->json($users->map(fn (User $user) => $this->userPayload($user))->values());
    }

    public function magazineAssignmentOptions(Request $request): JsonResponse
    {
        if (!$request->user() || !$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        $magazines = Magazine::query()
            ->select(['id', 'title', 'slug'])
            ->with(['editors' => function ($query) {
                $query->select(['users.id', 'users.name', 'users.role_id'])
                    ->with('role:id,name')
                    ->whereHas('role', function ($roleQuery) {
                        $roleQuery->whereIn('name', self::MAGAZINE_ASSIGNMENT_ROLE_NAMES);
                    })
                    ->wherePivotIn('role', ['editor', 'magazine_editor', 'publisher'])
                    ->orderBy('users.name');
            }])
            ->orderBy('title')
            ->get()
            ->map(function (Magazine $magazine) {
                $summary = [
                    'editors' => [],
                    'publishers' => [],
                ];

                foreach ($magazine->editors as $user) {
                    $pivotRole = str_replace('-', '_', (string) $user->pivot?->role);
                    $roleName = str_replace('-', '_', (string) $user->role?->name);

                    if (in_array($pivotRole, ['editor', 'magazine_editor'], true) || in_array($roleName, ['editor', 'magazine_editor'], true)) {
                        $summary['editors'][] = ['id' => $user->id, 'name' => $user->name];
                    } elseif ($pivotRole === 'publisher' || $roleName === 'publisher') {
                        $summary['publishers'][] = ['id' => $user->id, 'name' => $user->name];
                    }
                }

                return [
                    'id' => $magazine->id,
                    'title' => $magazine->title,
                    'slug' => $magazine->slug,
                    'assignment_summary' => collect($summary)
                        ->map(fn (array $users) => collect($users)->unique('id')->values()->all())
                        ->all(),
                ];
            })
            ->values();

        return response()->json(['magazines' => $magazines]);
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

        $assignedRole = Role::find($request->role_id);
        if ($this->isInactiveRole($assignedRole)) {
            return response()->json(['message' => 'Proofreader is inactive and cannot be assigned to users.'], 422);
        }
        $isSubEditor = $assignedRole && ($assignedRole->name === 'sub_editor' || $assignedRole->name === 'sub-editor');

        if ($isSubEditor) {
            $request->validate([
                'editor_ids' => 'required|array|min:1',
                'editor_ids.*' => 'integer|exists:users,id',
            ], [
                'editor_ids.required' => 'At least one Editor must be assigned to a Sub Editor.',
                'editor_ids.min' => 'At least one Editor must be assigned to a Sub Editor.',
            ]);

            foreach ($request->editor_ids as $editorId) {
                $editorUser = User::find($editorId);
                if (!$editorUser || !$editorUser->hasRole(['editor', 'magazine_editor', 'magazine-editor'])) {
                    return response()->json([
                        'message' => 'At least one Editor must be assigned to a Sub Editor.',
                        'errors' => ['editor_ids' => ['At least one Editor must be assigned to a Sub Editor.']]
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($user, $request, $isSubEditor) {
            $user->role_id = $request->role_id;
            $user->save();

            if ($isSubEditor) {
                $user->assignedEditors()->sync($request->editor_ids);
            } else {
                $user->assignedEditors()->detach();
            }
        });

        return response()->json($this->userPayload($user->load(['role', 'assignedEditors'])));
    }

    /**
     * Create a system user and send a password setup email.
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
            'university_name' => 'required|string|max:255',
            'magazine_ids' => 'nullable|array',
            'magazine_ids.*' => 'integer|exists:magazines,id',
        ]);

        $assignedRole = Role::find($request->role_id);
        if ($this->isInactiveRole($assignedRole)) {
            return response()->json(['message' => 'Proofreader is inactive and cannot be assigned to users.'], 422);
        }
        $isSubEditor = $assignedRole && ($assignedRole->name === 'sub_editor' || $assignedRole->name === 'sub-editor');

        if ($isSubEditor) {
            $request->validate([
                'editor_ids' => 'required|array|min:1',
                'editor_ids.*' => 'integer|exists:users,id',
            ], [
                'editor_ids.required' => 'At least one Editor must be assigned to a Sub Editor.',
                'editor_ids.min' => 'At least one Editor must be assigned to a Sub Editor.',
            ]);

            foreach ($request->editor_ids as $editorId) {
                $editorUser = User::find($editorId);
                if (!$editorUser || !$editorUser->hasRole(['editor', 'magazine_editor', 'magazine-editor'])) {
                    return response()->json([
                        'message' => 'At least one Editor must be assigned to a Sub Editor.',
                        'errors' => ['editor_ids' => ['At least one Editor must be assigned to a Sub Editor.']]
                    ], 422);
                }
            }
        }

        $user = DB::transaction(function() use ($request, $isSubEditor) {
            $user = User::create([
                'name' => $request->name,
                'email' => strtolower($request->email),
                'password' => null,
                'email_verified_at' => now(),
                'role_id' => $request->role_id,
                'university_name' => $request->university_name,
                'needs_password_reset' => true,
                'current_email_verified' => true,
            ]);

            $assignedRole = Role::find($request->role_id);
            if ($assignedRole && $this->roleUsesMagazineAssignments($assignedRole->name) && $request->has('magazine_ids')) {
                $user->magazines()->sync($this->magazineSyncPayload(
                    $request->input('magazine_ids', []),
                    $assignedRole->name,
                    $request->user()?->id
                ));
            }

            if ($isSubEditor) {
                $user->assignedEditors()->sync($request->editor_ids);
            }

            return $user;
        });

        $this->passwordSetupService->sendSetupLink($user);

        return response()->json([
            'message' => 'User created and password setup email sent.',
            'data' => $this->userPayload($user->load(['role', 'magazines'])),
        ], 201);
    }

    /**
     * Update user details and sync magazines if magazine_editor role.
     */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
            'role_id' => 'sometimes|required|exists:roles,id',
            'university_name' => 'sometimes|required|string|max:255',
            'magazine_ids' => 'nullable|array',
            'magazine_ids.*' => 'integer|exists:magazines,id',
        ]);

        $user = User::findOrFail($id);
        
        // Prevent users from de-roling themselves from super_admin
        if ($request->has('role_id') && $user->id === $request->user()->id && $user->role?->name === 'super_admin' && intval($request->role_id) !== $user->role_id) {
            return response()->json([
                'message' => 'You cannot remove your own super_admin role.'
            ], 400);
        }

        $assignedRoleId = $request->has('role_id') ? $request->role_id : $user->role_id;
        $assignedRole = Role::find($assignedRoleId);
        $isSubEditor = $assignedRole && ($assignedRole->name === 'sub_editor' || $assignedRole->name === 'sub-editor');

        if ($isSubEditor) {
            $request->validate([
                'editor_ids' => 'required|array|min:1',
                'editor_ids.*' => 'integer|exists:users,id',
            ], [
                'editor_ids.required' => 'At least one Editor must be assigned to a Sub Editor.',
                'editor_ids.min' => 'At least one Editor must be assigned to a Sub Editor.',
            ]);

            foreach ($request->editor_ids as $editorId) {
                $editorUser = User::find($editorId);
                if (!$editorUser || !$editorUser->hasRole(['editor', 'magazine_editor', 'magazine-editor'])) {
                    return response()->json([
                        'message' => 'At least one Editor must be assigned to a Sub Editor.',
                        'errors' => ['editor_ids' => ['At least one Editor must be assigned to a Sub Editor.']]
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($request, $user, $isSubEditor) {
            if ($request->has('name')) {
                $user->name = $request->name;
            }
            if ($request->has('email')) {
                $user->email = $request->email;
            }
            if ($request->has('role_id')) {
                $user->role_id = $request->role_id;
            }
            if ($request->has('university_name')) {
                $user->university_name = $request->university_name;
            }
            $user->save();

            if ($isSubEditor) {
                if ($request->has('editor_ids')) {
                    $user->assignedEditors()->sync($request->editor_ids);
                }
            } else {
                $user->assignedEditors()->detach();
            }

            $assignedRole = $user->role;
            if ($assignedRole && $this->roleUsesMagazineAssignments($assignedRole->name)) {
                if ($request->has('magazine_ids')) {
                    $user->magazines()->sync($this->magazineSyncPayload(
                        $request->input('magazine_ids', []),
                        $assignedRole->name,
                        $request->user()?->id
                    ));
                }
            } else {
                $user->magazines()->detach();
            }
        });

        return response()->json($this->userPayload($user->load(['role', 'magazines'])));
    }

    /**
     * Get the default registration role.
     */
    public function getRegistrationRole(): JsonResponse
    {
        $settings = $this->registrationSettingsPayload();

        return response()->json([
            'default_registration_role' => $settings['default_role']['name'] ?? 'author',
            'role' => $settings['default_role'],
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

        $role = Role::where('name', $request->default_registration_role)->first();
        if (!$role || !$this->isRegistrationEligibleRole($role)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['default_registration_role' => ['This role cannot be used for public registration.']],
            ], 422);
        }

        Setting::updateOrCreate(
            ['key' => 'default_registration_role'],
            ['value' => $role->name]
        );

        return response()->json([
            'message' => 'Default registration role updated successfully.'
        ]);
    }

    public function registrationSettings(): JsonResponse
    {
        return response()->json([
            'data' => $this->registrationSettingsPayload(),
        ]);
    }

    public function registrationRoleOptions(): JsonResponse
    {
        $roles = Role::query()
            ->whereIn('name', self::REGISTRATION_ELIGIBLE_ROLE_NAMES)
            ->orderBy('display_name')
            ->get()
            ->filter(fn (Role $role) => $this->isRegistrationEligibleRole($role))
            ->map(fn (Role $role) => $this->minimalRolePayload($role))
            ->values();

        return response()->json(['data' => $roles]);
    }

    public function updateRegistrationSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_enabled' => 'required|boolean',
            'default_role_id' => 'required|integer|exists:roles,id',
            'registration_notice' => 'nullable|string|max:500',
        ]);

        $role = Role::find($validated['default_role_id']);
        if (!$role || !$this->isRegistrationEligibleRole($role)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['default_role_id' => ['This role cannot be used for public registration.']],
            ], 422);
        }

        DB::transaction(function () use ($validated, $role) {
            Setting::updateOrCreate(
                ['key' => 'registration_enabled'],
                ['value' => $validated['registration_enabled'] ? '1' : '0']
            );
            Setting::updateOrCreate(
                ['key' => 'default_registration_role'],
                ['value' => $role->name]
            );
            Setting::updateOrCreate(
                ['key' => 'registration_notice'],
                ['value' => $validated['registration_notice'] ?? '']
            );
        });

        return response()->json([
            'message' => 'Registration settings updated successfully.',
            'data' => $this->registrationSettingsPayload(),
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

    private function roleUsesMagazineAssignments(string $roleName): bool
    {
        return in_array(str_replace('-', '_', $roleName), [
            'editor',
            'publisher',
            'magazine_editor',
        ], true);
    }

    private function isInactiveRole(?Role $role): bool
    {
        return $role && str_replace('-', '_', $role->name) === 'proofreader';
    }

    private function validateMagazineAssignmentRequest(Request $request, ?Role $role): array
    {
        $requiresMagazineAssignment = $role && $this->roleUsesMagazineAssignments($role->name);
        $magazineRules = [
            $requiresMagazineAssignment ? 'required' : 'nullable',
            'array',
        ];

        if ($requiresMagazineAssignment) {
            $magazineRules[] = 'min:1';
        }

        try {
            $validated = $request->validate([
                'magazine_ids' => $magazineRules,
                'magazine_ids.*' => 'integer|distinct|exists:magazines,id',
            ], [
                'magazine_ids.required' => 'At least one magazine must be assigned for this role.',
                'magazine_ids.min' => 'At least one magazine must be assigned for this role.',
                'magazine_ids.*.distinct' => 'Each selected magazine may only be assigned once.',
                'magazine_ids.*.exists' => 'One or more selected magazines are no longer available.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }

        if (!$requiresMagazineAssignment) {
            return [];
        }

        return collect($validated['magazine_ids'] ?? [])
            ->map(fn ($magazineId) => (int) $magazineId)
            ->unique()
            ->values()
            ->all();
    }

    private function syncRoleMagazineAssignments(User $user, ?Role $previousRole, ?Role $selectedRole, array $magazineIds, ?int $assignedBy): void
    {
        $previousPivotRoles = $previousRole ? $this->pivotRolesForUserRole($previousRole->name) : [];
        $selectedPivotRoles = $selectedRole ? $this->pivotRolesForUserRole($selectedRole->name) : [];
        $rolesToReplace = collect($previousPivotRoles)
            ->merge($selectedPivotRoles)
            ->unique()
            ->values()
            ->all();

        if ($rolesToReplace === []) {
            return;
        }

        DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where(function ($query) use ($rolesToReplace) {
                $query->whereIn('role', $rolesToReplace)
                    ->orWhereNull('role');
            })
            ->delete();

        if (!$selectedRole || !$this->roleUsesMagazineAssignments($selectedRole->name)) {
            return;
        }

        $pivotRole = $this->pivotRoleForUserRole($selectedRole->name);
        $now = now();
        $rows = collect($magazineIds)
            ->map(fn (int $magazineId) => [
                'user_id' => $user->id,
                'magazine_id' => $magazineId,
                'role' => $pivotRole,
                'assigned_by' => $assignedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('magazine_user')->insert($rows);
        }
    }

    private function pivotRolesForUserRole(string $roleName): array
    {
        $pivotRole = $this->pivotRoleForUserRole($roleName);

        return $pivotRole ? [$pivotRole] : [];
    }

    private function pivotRoleForUserRole(string $roleName): ?string
    {
        $normalizedRole = str_replace('-', '_', $roleName);

        return match ($normalizedRole) {
            'editor', 'magazine_editor' => 'editor',
            'publisher' => 'publisher',
            default => null,
        };
    }

    private function safeAssignedMagazinesForEdit(User $user): array
    {
        $selectedRole = $user->role;
        if (!$selectedRole || !$this->roleUsesMagazineAssignments($selectedRole->name)) {
            return [];
        }

        $selectedPivotRole = $this->pivotRoleForUserRole($selectedRole->name);

        return $user->magazines
            ->filter(fn ($magazine) => $magazine->pivot?->role === $selectedPivotRole || $magazine->pivot?->role === null)
            ->map(fn ($magazine) => [
                'id' => $magazine->id,
                'title' => $magazine->title,
                'role' => $magazine->pivot?->role,
            ])
            ->values()
            ->all();
    }

    private function rolePayload(Role $role): array
    {
        $isLocked = (bool) $role->is_system
            || in_array($role->name, SystemRoles::names(), true)
            || $role->name === 'admin';

        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'is_locked' => $isLocked,
            'permissions' => $role->permissions
                ? $role->permissions->map(fn (Permission $permission) => $this->permissionPayload($permission))->values()
                : [],
        ];
    }

    private function registrationSettingsPayload(): array
    {
        $roleName = Setting::where('key', 'default_registration_role')->value('value') ?? 'author';
        $role = Role::where('name', $roleName)->first();
        if (!$role || !$this->isRegistrationEligibleRole($role)) {
            $role = Role::where('name', 'author')->first();
        }

        return [
            'registration_enabled' => $this->settingBoolean('registration_enabled', true),
            'default_role' => $role ? $this->minimalRolePayload($role) : null,
            'email_verification_required' => true,
            'registration_notice' => Setting::where('key', 'registration_notice')->value('value')
                ?? 'Create an author account to submit manuscripts.',
        ];
    }

    private function minimalRolePayload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
        ];
    }

    private function isRegistrationEligibleRole(Role $role): bool
    {
        return in_array($role->name, self::REGISTRATION_ELIGIBLE_ROLE_NAMES, true);
    }

    private function settingBoolean(string $key, bool $default): bool
    {
        $value = Setting::where('key', $key)->value('value');
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function permissionPayload(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'display_name' => $this->permissionDisplayName($permission->name),
            'module' => $permission->module,
            'description' => $permission->description,
        ];
    }

    private function normalizeRoleName(string $name): string
    {
        return strtolower(Str::slug($name));
    }

    private function permissionDisplayName(string $name): string
    {
        $label = Str::of($name)
            ->replace(['.', '-', '_'], ' ')
            ->headline()
            ->toString();

        return str_replace(['Seo', 'Cms'], ['SEO', 'CMS'], $label);
    }

    private function isProtectedAssignmentPermission(string $name): bool
    {
        return in_array($name, self::PROTECTED_ASSIGNMENT_PERMISSIONS, true)
            || str_contains($name, 'impersonat');
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'university_name' => $user->university_name,
            'profile_image' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
            'profile_image_url' => app(MediaStorageService::class)->applicationUrl($user->profile_image),
            'email_verified_at' => $user->email_verified_at,
            'needs_password_reset' => (bool) $user->needs_password_reset,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'display_name' => $user->role->display_name,
            ] : null,
            'assigned_editors' => $user->hasRole('sub_editor') && $user->relationLoaded('assignedEditors')
                ? $user->assignedEditors->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'email' => $e->email
                ])->values()->all()
                : [],
            'magazines' => $user->relationLoaded('magazines')
                ? $user->magazines->map(fn ($magazine) => [
                    'id' => $magazine->id,
                    'title' => $magazine->title,
                    'slug' => $magazine->slug,
                    'pivot' => [
                        'role' => $magazine->pivot?->role,
                    ],
                ])->values()
                : [],
            'created_at' => $user->created_at,
        ];
    }

    private function magazineSyncPayload(array $magazineIds, string $roleName, ?int $assignedBy): array
    {
        $normalizedRole = str_replace('-', '_', $roleName);
        if ($normalizedRole === 'magazine_editor') {
            $normalizedRole = 'editor';
        }

        return collect($magazineIds)
            ->mapWithKeys(fn ($magazineId) => [
                $magazineId => [
                    'role' => $normalizedRole,
                    'assigned_by' => $assignedBy,
                ],
            ])
            ->all();
    }
}
