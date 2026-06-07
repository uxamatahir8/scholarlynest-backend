<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // 1. Direct match or universal override check
        // e.g. if the check is for articles.edit-own, check if they have articles.edit-any
        $anyPermission = str_replace('-own', '-any', $permission);
        
        if ($user->hasPermission($permission) || $user->hasPermission($anyPermission)) {
            return $next($request);
        }

        // Special override: articles.auto-approve is allowed to perform articles.approve tasks (review/approval)
        if ($permission === 'articles.approve' && $user->hasPermission('articles.auto-approve')) {
            return $next($request);
        }

        // 2. Scoped Ownership validation (e.g. articles.edit-own or articles.view-own)
        if (str_ends_with($permission, '-own')) {
            $resource = null;
            $route = $request->route();

            if ($route) {
                foreach ($route->parameters() as $key => $value) {
                    if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                        $resource = $value;
                        break;
                    }

                    // Dynamically resolve model based on permission prefix if only key is passed
                    $parts = explode('.', $permission);
                    $module = $parts[0] ?? null;
                    $modelClass = null;

                    if ($module === 'articles') {
                        $modelClass = \App\Models\Article::class;
                    } elseif ($module === 'magazines') {
                        $modelClass = \App\Models\Magazine::class;
                    }

                    if ($modelClass && (is_numeric($value) || is_string($value))) {
                        $queryField = is_numeric($value) ? 'id' : 'slug';
                        $resource = $modelClass::where($queryField, $value)->first();
                        if ($resource) {
                            break;
                        }
                    }
                }
            }

            // Verify if resource belongs to the current user
            if ($resource) {
                if ($resource instanceof \App\Models\Article) {
                    if ($resource->user_id === $user->id) {
                        return $next($request);
                    }
                    $isCoAuthorEditor = \DB::table('article_author')
                        ->where('article_id', $resource->id)
                        ->where('user_id', $user->id)
                        ->where('can_edit', true)
                        ->exists();
                    if ($isCoAuthorEditor) {
                        return $next($request);
                    }
                } else {
                    if (isset($resource->user_id) && $resource->user_id === $user->id) {
                        return $next($request);
                    }
                }
            }
        }

        return response()->json([
            'message' => 'This action is unauthorized. You do not possess the required permissions.'
        ], 403);
    }
}
