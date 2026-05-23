<?php

namespace App\Http\Controllers;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MagazineController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * GET /api/magazines
     * Returns all or paginated magazines with the count of approved articles.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Magazine::withCount(['articles' => function ($query) {
            $query->where('status', 'approved');
        }]);

        if ($request->boolean('all', false)) {
            return response()->json($query->get());
        }

        $perPage = $request->integer('per_page', 8);
        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/magazines/{slug}
     * Returns the magazine shell along with sorted magazine_pages.
     */
    public function show(string $slug): JsonResponse
    {
        $magazine = Magazine::where('slug', $slug)
            ->with(['pages' => function ($query) {
                $query->orderBy('sort_order', 'asc');
            }])
            ->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        return response()->json($magazine);
    }

    /**
     * GET /api/magazines/{slug}/articles
     * Public paginated feed of approved articles under a specific magazine.
     */
    public function articles(string $slug): JsonResponse
    {
        $magazine = Magazine::where('slug', $slug)->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $articles = $magazine->articles()
            ->where('status', 'approved')
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($articles);
    }

    /**
     * POST /api/admin/magazines
     * Create a new magazine (Admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Forbidden. Admin privileges required.'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'cover_image' => 'nullable', // can be file or string
            'description' => 'nullable|string',
            'about_text' => 'nullable|string',
        ]);

        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('covers', 'public');
            $coverImagePath = 'storage/' . $path;
        } elseif (is_string($request->input('cover_image'))) {
            $coverImagePath = $request->input('cover_image');
        }

        $slug = Str::slug($validated['title']) . '-' . Str::random(5);

        $magazine = Magazine::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'cover_image' => $coverImagePath,
            'description' => $validated['description'] ?? null,
            'about_text' => $validated['about_text'] ?? null,
        ]);

        // Dispatch newsletter announcement to subscribers
        try {
            $subscribers = \App\Models\NewsletterSubscriber::where('is_active', true)->get();
            $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');

            foreach ($subscribers as $sub) {
                $unsubscribeUrl = "{$frontendUrl}/unsubscribe/{$sub->token}";
                
                $bodyLines = [
                    'We are pleased to announce the publication of our latest issue: <strong>' . e($magazine->title) . '</strong>.',
                ];

                if ($magazine->cover_image) {
                    $bodyLines[] = '<div style="text-align: center; margin-bottom: 24px;"><img src="' . $frontendUrl . '/' . $magazine->cover_image . '" alt="Cover Image" style="max-width: 200px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);"></div>';
                }

                if ($magazine->description) {
                    $bodyLines[] = '<p style="font-size: 13px; color: #52525b; line-height: 1.6; font-style: italic; margin-bottom: 24px;">' . e($magazine->description) . '</p>';
                }

                $action = [
                    'text' => 'Explore Magazine Issue',
                    'url' => "{$frontendUrl}/magazines/{$magazine->slug}",
                ];

                $this->notificationService->send(
                    $sub->email,
                    "New Issue Released: " . $magazine->title,
                    'Announcing a New Issue',
                    $bodyLines,
                    $action,
                    'default',
                    null,
                    null,
                    null,
                    $unsubscribeUrl
                );
            }
        } catch (\Exception $e) {
            logger()->error("Error sending newsletter announcement: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Magazine created successfully.',
            'magazine' => $magazine
        ], 211);
    }

    /**
     * POST /api/admin/magazines/{id}/pages
     * Create or update custom sub-pages for a magazine (Admin only).
     */
    public function storePage(Request $request, int $magazineId): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Forbidden. Admin privileges required.'], 403);
        }

        $magazine = Magazine::find($magazineId);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'sort_order' => 'integer',
        ]);

        $slug = Str::slug($validated['title']);
        
        // Ensure slug uniqueness within this magazine
        $slugBase = $slug;
        $counter = 1;
        while (MagazinePage::where('magazine_id', $magazineId)->where('slug', $slug)->exists()) {
            $slug = $slugBase . '-' . $counter;
            $counter++;
        }

        $page = $magazine->pages()->create([
            'title' => $validated['title'],
            'slug' => $slug,
            'content' => $validated['content'],
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Magazine sub-page created successfully.',
            'page' => $page
        ], 211);
    }

    /**
     * PUT /api/admin/magazines/{id}
     * Update an existing magazine (Admin only).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Forbidden. Admin privileges required.'], 403);
        }

        $magazine = Magazine::find($id);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'cover_image' => 'nullable',
            'description' => 'nullable|string',
            'about_text' => 'nullable|string',
        ]);

        $coverImagePath = $magazine->cover_image;
        if ($request->hasFile('cover_image')) {
            if ($coverImagePath && strpos($coverImagePath, 'storage/') === 0) {
                $oldPath = str_replace('storage/', '', $coverImagePath);
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('cover_image')->store('covers', 'public');
            $coverImagePath = 'storage/' . $path;
        } elseif ($request->has('cover_image')) {
            $coverImagePath = $request->input('cover_image');
        }

        $magazine->update([
            'title' => $validated['title'],
            'cover_image' => $coverImagePath,
            'description' => $validated['description'] ?? null,
            'about_text' => $validated['about_text'] ?? null,
        ]);

        return response()->json([
            'message' => 'Magazine updated successfully.',
            'magazine' => $magazine
        ]);
    }

    /**
     * DELETE /api/admin/magazines/{id}
     * Delete an existing magazine (Admin only).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Forbidden. Admin privileges required.'], 403);
        }

        $magazine = Magazine::find($id);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        // Delete associated pages and articles (cascades or manual depending on database constraints)
        $magazine->pages()->delete();
        $magazine->articles()->delete();
        $magazine->delete();

        return response()->json([
            'message' => 'Magazine and its related resources deleted successfully.'
        ]);
    }

    /**
     * PUT /api/admin/magazines/{magazineId}/pages/{pageId}
     * Update an existing sub-page (Admin only).
     */
    public function updatePage(Request $request, int $magazineId, int $pageId): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Forbidden. Admin privileges required.'], 403);
        }

        $magazine = Magazine::find($magazineId);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $page = $magazine->pages()->find($pageId);
        if (!$page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'sort_order' => 'integer',
        ]);

        $page->update($validated);

        return response()->json([
            'message' => 'Magazine page updated successfully.',
            'page' => $page
        ]);
    }

    /**
     * DELETE /api/admin/magazines/{magazineId}/pages/{pageId}
     * Delete an existing sub-page (Admin only).
     */
    public function destroyPage(Request $request, int $magazineId, int $pageId): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json(['message' => 'Forbidden. Admin privileges required.'], 403);
        }

        $magazine = Magazine::find($magazineId);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $page = $magazine->pages()->find($pageId);
        if (!$page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $page->delete();

        return response()->json([
            'message' => 'Magazine page deleted successfully.'
        ]);
    }
}
