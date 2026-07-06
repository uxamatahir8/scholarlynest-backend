<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ImpersonationSession;

class ValidateImpersonationSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $token = $user->currentAccessToken();

            if ($token && $token->name === 'impersonation_token') {
                // If target user is not active (email_verified_at is null), reject the session
                if (!$user->email_verified_at) {
                    $token->delete();
                    $session = ImpersonationSession::where('impersonation_token_id', $token->id)->first();
                    if ($session) {
                        $session->update(['status' => 'revoked']);
                    }
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }

                // If session is expired, stopped, or revoked
                $session = ImpersonationSession::where('impersonation_token_id', $token->id)->first();
                if (!$session || $session->status !== 'active' || now()->gt($session->expires_at)) {
                    $token->delete();
                    if ($session && $session->status === 'active') {
                        $session->update(['status' => 'expired']);
                    }
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
            }
        }

        return $next($request);
    }
}
