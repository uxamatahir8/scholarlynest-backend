<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $user->currentAccessToken();
        if ($token && $token->name === 'impersonation_token') {
            if ($request->is('api/admin/users/*/impersonate')) {
                return response()->json(['message' => 'Impersonated sessions cannot start impersonation.'], 400);
            }
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        if (!$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can access this resource.'], 403);
        }

        return $next($request);
    }
}
