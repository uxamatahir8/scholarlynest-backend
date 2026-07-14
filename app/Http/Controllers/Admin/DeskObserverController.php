<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeskObserverController extends Controller
{
    public const EDITOR_ROLES = [
        'editor',
        'super_editor',
        'magazine_editor',
        'journal_editor',
    ];

    public const SUPPORTED_ROLES = [
        'reviewer',
        'sub_editor',
        'copy_editor',
        'proofreader',
        'publisher',
        'editor',
    ];

    public function users(Request $request): JsonResponse
    {
        if (!$this->canUseObserverMode($request)) {
            return response()->json(['message' => 'Forbidden. Only non-impersonated Super Admin can use desk observer mode.'], 403);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in(self::SUPPORTED_ROLES)],
        ]);

        $role = str_replace('-', '_', $validated['role']);

        $users = User::query()
            ->select(['id', 'name', 'role_id'])
            ->with('role:id,name')
            ->whereNotNull('email_verified_at')
            ->whereHas('role', function ($query) use ($role) {
                if ($role === 'editor') {
                    $query->whereIn('name', self::roleNameVariants(self::EDITOR_ROLES));
                    return;
                }

                $query->whereIn('name', self::roleNameVariants([$role]));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => str_replace('-', '_', (string) $user->role?->name),
            ])
            ->values();

        return response()->json(['users' => $users]);
    }

    public static function canUseObserverMode(Request $request): bool
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        return $user
            && $user->hasRole('super_admin')
            && (!$token || $token->name !== 'impersonation_token');
    }

    public static function resolveObservedUser(Request $request, string|array $roles): ?User
    {
        if (!$request->filled('observer_user_id')) {
            return null;
        }

        if (!self::canUseObserverMode($request)) {
            abort(response()->json(['message' => 'Forbidden. Only non-impersonated Super Admin can use desk observer mode.'], 403));
        }

        $observerUserId = $request->integer('observer_user_id');
        if ($observerUserId < 1) {
            abort(response()->json(['message' => 'Invalid observer user.'], 422));
        }

        $allowedRoles = collect((array) $roles)
            ->map(fn ($role) => str_replace('-', '_', (string) $role))
            ->flatMap(fn ($role) => $role === 'editor' ? self::EDITOR_ROLES : [$role])
            ->unique()
            ->values();

        $user = User::query()
            ->with('role:id,name')
            ->whereKey($observerUserId)
            ->whereNotNull('email_verified_at')
            ->first();

        if (!$user || !$allowedRoles->contains(str_replace('-', '_', (string) $user->role?->name))) {
            abort(response()->json(['message' => 'The selected observer user is not available for this desk.'], 422));
        }

        return $user;
    }

    private static function roleNameVariants(array $roles): array
    {
        return collect($roles)
            ->flatMap(fn ($role) => [$role, str_replace('_', '-', $role)])
            ->unique()
            ->values()
            ->all();
    }
}
