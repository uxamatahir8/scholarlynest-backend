<?php

namespace App\Http\Middleware;

use App\Models\Article;
use App\Models\Magazine;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // 1. Universal override check (any)
        $anyPermission = str_replace('-own', '-any', $permission);
        if ($user->hasPermission($anyPermission)) {
            return $next($request);
        }

        // 2. Determine if user has the specific permission (non-any)
        if (! $user->hasPermission($permission)) {
            return response()->json([
                'message' => 'This action is unauthorized. You do not possess the required permissions.',
            ], 403);
        }

        // 3. Scoped Ownership validation (if the permission ends in -own)
        if (str_ends_with($permission, '-own')) {
            $resource = null;
            $route = $request->route();

            if ($route) {
                foreach ($route->parameters() as $key => $value) {
                    if ($value instanceof Model) {
                        $resource = $value;
                        break;
                    }

                    // Dynamically resolve model based on permission prefix if only key is passed
                    $parts = explode('.', $permission);
                    $module = $parts[0] ?? null;
                    $modelClass = null;

                    if ($module === 'articles') {
                        $modelClass = Article::class;
                    } elseif ($module === 'magazines') {
                        $modelClass = Magazine::class;
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

                if ($resource instanceof Article) {
                    if ($resource->isPrimaryOrCorrespondingAuthor($user)) {
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
                    $email = strtolower(trim((string) $user->email));
                    $isCoAuthorEditor = \DB::table('article_author')
                        ->where('article_id', $resource->id)
                        ->where('can_edit', true)
                        ->where(function ($query) use ($user, $email) {
                            $query->where('user_id', $user->id);

                            if ($email !== '') {
                                $query->orWhereRaw('LOWER(co_author_email) = ?', [$email]);
                            }
                        })
                        ->exists();
                    if ($isCoAuthorEditor) {
                        return $next($request);
                    }
                } elseif ($resource instanceof Magazine) {
                    $typeAllowed = ! $user->isPublicationEditor()
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
                    'message' => 'This action is unauthorized. You do not possess the required permissions.',
                ], 403);
            }
        }

        return $next($request);
    }
}
