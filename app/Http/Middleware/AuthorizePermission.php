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



        // 1. Universal override check (any)
        $anyPermission = str_replace('-own', '-any', $permission);
        if ($user->hasPermission($anyPermission)) {
            return $next($request);
        }

        // Special override: articles.auto-approve is allowed to perform articles.approve tasks (review/approval)
        if ($permission === 'articles.approve' && $user->hasPermission('articles.auto-approve')) {
            return $next($request);
        }

        // 2. Determine if user has the specific permission (non-any)
        if (!$user->hasPermission($permission)) {
            return response()->json([
                'message' => 'This action is unauthorized. You do not possess the required permissions.'
            ], 403);
        }

        // 3. Scoped Ownership validation (if the permission ends in -own)
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

            // Verify if resource belongs to the current user or their assigned magazine(s)
            if ($resource) {

                if ($resource instanceof \App\Models\Article) {
                    if ($resource->user_id === $user->id) {
                        return $next($request);
                    }
                    $isMagazineAssigned = \DB::table('magazine_user')
                        ->where('user_id', $user->id)
                        ->where('magazine_id', $resource->magazine_id)
                        ->where(function ($query) {
                            $query->whereIn('role', [
                                'editor',

                                'sub_editor',
                                'reviewer',
                                'publisher',
                                'copy_editor',
                                'proofreader',
                            ])->orWhereNull('role');
                        })
                        ->exists();
                    if ($isMagazineAssigned) {
                        return $next($request);
                    }
                    if ($user->hasRole('sub_editor') && \DB::table('sub_editor_assignments')
                        ->where('article_id', $resource->id)
                        ->where('sub_editor_id', $user->id)
                        ->exists()) {
                        return $next($request);
                    }
                    if ($user->hasRole('reviewer') && \DB::table('reviewer_assignments')
                        ->where('article_id', $resource->id)
                        ->where('reviewer_id', $user->id)
                        ->exists()) {
                        return $next($request);
                    }
                    if (($user->hasRole('copy_editor') || $user->hasRole('proofreader')) && \DB::table('production_assignments')
                        ->where('article_id', $resource->id)
                        ->where('user_id', $user->id)
                        ->exists()) {
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
                } elseif ($resource instanceof \App\Models\Magazine) {
                    $typeAllowed = !$user->isPublicationEditor()
                        || in_array($resource->publication_type, $user->editorPublicationTypes(), true);
                    $isPublicationAssigned = $typeAllowed && \DB::table('magazine_user')
                        ->where('user_id', $user->id)
                        ->where('magazine_id', $resource->id)
                        ->where(function ($query) {
                            $query->whereIn('role', ['editor', 'publisher'])->orWhereNull('role');
                        })
                        ->exists();
                    if ($isPublicationAssigned) {
                        return $next($request);
                    }
                } else {
                    if (isset($resource->user_id) && $resource->user_id === $user->id) {
                        return $next($request);
                    }
                }

                // If resource was resolved but ownership validation failed
                return response()->json([
                    'message' => 'This action is unauthorized. You do not possess the required permissions.'
                ], 403);
            }
        }

        return $next($request);
    }
}
