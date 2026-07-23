<?php

namespace App\Http\Controllers;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\SharedPublicPage;
use App\Services\Media\MediaStorageService;
use App\Services\Media\CleanUploadResolver;
use App\Services\NotificationService;
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MagazineController extends Controller
{
    protected NotificationService $notificationService;
    protected MediaStorageService $mediaStorage;

    public function __construct(NotificationService $notificationService, MediaStorageService $mediaStorage)
    {
        $this->notificationService = $notificationService;
        $this->mediaStorage = $mediaStorage;
    }
    /**
     * GET /api/magazines
     * Returns all or paginated magazines with the count of approved articles.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Magazine::with('editors')->withCount(['articles' => function ($query) {
            $query->where('status', 'published');
        }]);

        $this->applyPublicationTypeFilter($query, $request);

        $user = $request->user('sanctum') ?: $request->user();


        if ($request->boolean('all', false)) {
            return response()->json($query->get());
        }

        $perPage = $request->integer('per_page', 8);
        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/magazines/latest
     * Returns the latest 10 magazines, ordered explicitly by creation date.
     */
    public function latest(Request $request): JsonResponse
    {
        $query = Magazine::withCount(['articles' => function ($query) {
            $query->where('status', 'published');
        }]);
        $this->applyPublicationTypeFilter($query, $request);
        $magazines = $query->latest()
        ->limit(10)
        ->get();

        return response()->json([
            'status' => 'success',
            'data' => $magazines
        ]);
    }

    /**
     * GET /api/admin/magazines
     * Authenticated management list scoped to the user's assigned magazines.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $query = Magazine::with('editors')->withCount(['articles' => function ($query) {
            $query->where('status', 'published');
        }]);
        $this->applyPublicationTypeFilter($query, $request);

        if (!$this->hasGlobalMagazineAccess($user)) {
            $assignedMagazineIds = $this->assignedMagazineIds($user, ['editor', 'publisher']);
            if (empty($assignedMagazineIds)) {
                return response()->json(['message' => 'Forbidden. Magazine assignment required.'], 403);
            }
            $query->whereIn('id', $assignedMagazineIds);
        }

        if ($request->boolean('all', false)) {
            return response()->json($query->orderBy('title')->get());
        }

        return response()->json($query->orderByDesc('created_at')->paginate($request->integer('per_page', 8)));
    }

    /**
     * GET /api/admin/magazines/{slug}
     * Authenticated management detail scoped to assigned magazines.
     */
    public function adminShow(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $magazine = Magazine::where('slug', $slug)
            ->where('publication_type', $this->requestedPublicationType($request))
            ->with(['editors', 'pages' => function ($query) {
                $query->orderBy('sort_order', 'asc');
            }])
            ->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        if (!$this->hasGlobalMagazineAccess($user) && !$this->isAssignedMagazineRole($user, $magazine->id, ['editor', 'publisher'])) {
            return response()->json(['message' => 'Forbidden. You are not assigned to this magazine.'], 403);
        }

        if (!$this->hasGlobalMagazineAccess($user) && $user->hasRole('publisher')) {
            $magazine->setRelation('pages', collect());
        }

        return response()->json($magazine);
    }

    /**
     * GET /api/magazines/{slug}
     * Returns only the public magazine shell and active navigation pages.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug, $this->requestedPublicationType($request))->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        return response()->json($this->publicShellPayload($magazine));
    }

    /**
     * GET /api/magazines/{slug}/about-and-overview
     * Returns public about/overview data for one magazine only.
     */
    public function aboutAndOverview(Request $request, string $slug): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug, $this->requestedPublicationType($request))->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        return response()->json([
            'magazine' => array_merge($this->publicMagazinePayload($magazine), [
                'about_text' => $magazine->about_text,
            ]),
            'seo' => $this->magazineSeoPayload($magazine, 'About & Overview'),
        ]);
    }

    /**
     * GET /api/magazines/{slug}/latest-published-articles
     * Returns up to 10 latest published articles for one magazine only.
     */
    public function latestPublishedArticles(string $slug, Request $request): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug, $this->requestedPublicationType($request))->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $limit = min($request->integer('limit', 10), 10);
        $articles = $this->publishedArticleQuery($magazine)
            ->limit($limit)
            ->get()
            ->map(fn ($article) => $this->publicArticlePayload($article))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $articles,
        ]);
    }

    /**
     * GET /api/magazines/{slug}/table-of-contents
     * Returns public table of contents grouped by article publication date for one magazine only.
     */
    public function tableOfContents(Request $request, string $slug): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug, $this->requestedPublicationType($request))->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $articles = $this->publishedArticleQuery($magazine)
            ->get()
            ->sortByDesc(fn ($article) => $this->tocPublicationDate($article)?->timestamp ?? 0)
            ->values();

        $tableOfContents = $articles
            ->groupBy(fn ($article) => (string) $this->tocPublicationDate($article)->year)
            ->sortKeysDesc()
            ->map(function ($yearArticles, $year) {
                $months = $yearArticles
                    ->groupBy(fn ($article) => str_pad((string) $this->tocPublicationDate($article)->month, 2, '0', STR_PAD_LEFT))
                    ->sortKeysDesc()
                    ->map(function ($monthArticles, $monthKey) {
                        $firstDate = $this->tocPublicationDate($monthArticles->first());

                        return [
                            'month' => (int) $monthKey,
                            'month_name' => $firstDate->format('F'),
                            'articles' => $monthArticles
                                ->sortByDesc(fn ($article) => $this->tocPublicationDate($article)?->timestamp ?? 0)
                                ->map(fn ($article) => $this->publicArticlePayload($article))
                                ->values(),
                        ];
                    });

                return [
                    'year' => (int) $year,
                    'months' => $months->isEmpty() ? (object) [] : $months,
                ];
            });

        return response()->json([
            'magazine' => $this->publicMagazinePayload($magazine),
            'table_of_contents' => $tableOfContents->isEmpty() ? (object) [] : $tableOfContents,
            'seo' => $this->magazineSeoPayload($magazine, 'Table of Contents'),
        ]);
    }

    /**
     * GET /api/magazines/{slug}/pages/{pageSlug}
     * Returns one public active custom page scoped to the requested magazine.
     */
    public function publicPage(Request $request, string $slug, string $pageSlug): JsonResponse
    {
        if ($this->isReservedPublicPageSlug($pageSlug)) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $magazine = $this->publicMagazineQuery($slug, $this->requestedPublicationType($request))->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $page = $magazine->pages()
            ->where('slug', $pageSlug)
            ->where('status', 'active')
            ->first();

        $isShared = false;
        if (!$page) {
            $page = SharedPublicPage::query()->visibleFor($magazine)->where('slug', $pageSlug)->first();
            $isShared = (bool) $page;
        }
        if (!$page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        return response()->json([
            'magazine' => $this->publicMagazinePayload($magazine),
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'content' => $page->content,
                'is_shared' => $isShared,
            ],
            'seo' => [
                'title' => ($isShared && $page->seo_title ? $page->seo_title : $page->title) . ' | ' . $magazine->title . ' | ScholarlyNest',
                'description' => $isShared && $page->seo_description ? $page->seo_description : Str::limit(strip_tags($page->content), 160, ''),
                'keywords' => $magazine->seo_keywords ?: '',
                'og_image' => $magazine->cover_image_url,
            ],
        ]);
    }

    /**
     * GET /api/magazines/{slug}/articles
     * Public paginated feed of published articles under a specific magazine.
     */
    public function articles(Request $request, string $slug): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug, $this->requestedPublicationType($request))->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $articles = $magazine->articles()
            ->where('status', 'published')
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($articles);
    }

    /**
     * POST /api/admin/magazines
     * Create a new magazine (Super Admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Super Admin privileges required.'], 403);
        }

        $validated = $request->validate([
            'publication_type' => 'sometimes|in:magazine,journal',
            'title' => 'required|string|max:255',
            'cover_image' => 'nullable', // can be file or string
            'cover_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'main_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'banner_image' => 'nullable',
            'banner_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'description' => 'nullable|string',
            'about_text' => 'nullable|string',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
            'editor_id' => 'nullable|integer|exists:users,id',
            'editor_user_ids' => 'nullable|array',
            'editor_user_ids.*' => 'integer|exists:users,id',
        ]);

        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            return response()->json(['message' => 'Raw browser uploads are disabled for magazine covers. Use the direct S3 upload-session flow.'], 410);
        } elseif (!empty($validated['main_image_upload_id'] ?? $validated['cover_image_upload_id'] ?? null)) {
            $coverImagePath = app(CleanUploadResolver::class)->cleanKey($user, $validated['main_image_upload_id'] ?? $validated['cover_image_upload_id'], 'magazine_cover');
        }
        $bannerImagePath = !empty($validated['banner_image_upload_id'])
            ? app(CleanUploadResolver::class)->cleanKey($user, $validated['banner_image_upload_id'], 'publication_banner_image')
            : null;

        $magazineData = [
            'title' => $validated['title'],
            'cover_image' => $coverImagePath,
            'banner_image' => $bannerImagePath,
            'description' => $validated['description'] ?? null,
            'about_text' => $validated['about_text'] ?? null,
            'publication_type' => $this->requestedPublicationType($request),
        ];

        if ($user->hasPermission('seo.magazines')) {
            $magazineData['seo_title'] = $validated['seo_title'] ?? null;
            $magazineData['seo_description'] = $validated['seo_description'] ?? null;
            $magazineData['seo_keywords'] = $validated['seo_keywords'] ?? null;
        }

        $magazine = app(SlugService::class)->createPublication($magazineData);

        // Sync editors
        $editorUserIds = [];
        if ($request->has('editor_user_ids')) {
            $editorUserIds = (array) $request->input('editor_user_ids');
        } elseif ($request->has('editor_id')) {
            $editorId = $request->input('editor_id');
            if (!empty($editorId)) {
                $editorUserIds = [intval($editorId)];
            }
        }
        $magazine->editors()->sync($editorUserIds);

        // Dispatch newsletter announcement to subscribers
        try {
            $subscribers = \App\Models\NewsletterSubscriber::where('is_active', true)->get();
            $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');

            foreach ($subscribers as $sub) {
                $unsubscribeUrl = "{$frontendUrl}/unsubscribe/{$sub->token}";
                
                $bodyLines = [
                    'We are pleased to announce the publication of our latest issue: <strong>' . e($magazine->title) . '</strong>.',
                ];

                if ($magazine->cover_image_url) {
                    $bodyLines[] = '<div style="text-align: center; margin-bottom: 24px;"><img src="' . e($magazine->cover_image_url) . '" alt="Cover Image" style="max-width: 200px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);"></div>';
                }

                if ($magazine->description) {
                    $bodyLines[] = '<p style="font-size: 13px; color: #52525b; line-height: 1.6; font-style: italic; margin-bottom: 24px;">' . e($magazine->description) . '</p>';
                }

                $action = [
                    'text' => 'Explore ' . $magazine->publicationTypeLabel(),
                    'url' => "{$frontendUrl}/{$magazine->publicRoutePrefix()}/{$magazine->slug}",
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
            'message' => $magazine->publicationTypeLabel() . ' created successfully.',
            'magazine' => $magazine->load('editors')
        ], 211);
    }

    /**
     * POST /api/admin/magazines/{id}/pages
     * Create custom sub-pages for a magazine (Admin or Super Admin only).
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
            'slug' => 'nullable|string|max:255',
            'content' => 'required|string',
            'sort_order' => 'integer',
            'status' => 'nullable|in:active,draft,private,inactive',
        ]);

        $slug = $this->uniquePageSlug($magazineId, $validated['slug'] ?? $validated['title']);

        $page = $magazine->pages()->create([
            'title' => $validated['title'],
            'slug' => $slug,
            'content' => $validated['content'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'status' => $validated['status'] ?? 'active',
            'created_by' => $user->id,
            'created_by_role' => $user->role?->name,
            'is_editor_created' => $user->hasRole('editor'),
        ]);

        return response()->json([
            'message' => 'Magazine sub-page created successfully.',
            'page' => $page
        ], 211);
    }

    /**
     * PUT /api/admin/magazines/{id}
     * Update an existing magazine (Super Admin only).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Super Admin privileges required.'], 403);
        }

        $magazine = Magazine::find($id);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        if ($magazine->publication_type !== $this->requestedPublicationType($request)) {
            return response()->json(['message' => 'Publication not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'cover_image' => 'nullable',
            'cover_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'main_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'banner_image' => 'nullable',
            'banner_image_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
            'description' => 'nullable|string',
            'about_text' => 'nullable|string',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
        ]);

        $coverImagePath = $magazine->cover_image;
        if ($request->hasFile('cover_image')) {
            return response()->json(['message' => 'Raw browser uploads are disabled for magazine covers. Use the direct S3 upload-session flow.'], 410);
        } elseif (!empty($validated['main_image_upload_id'] ?? $validated['cover_image_upload_id'] ?? null)) {
            $newCoverImagePath = app(CleanUploadResolver::class)->cleanKey($user, $validated['main_image_upload_id'] ?? $validated['cover_image_upload_id'], 'magazine_cover');
            if ($newCoverImagePath !== $coverImagePath) {
                $this->mediaStorage->delete($coverImagePath);
            }
            $coverImagePath = $newCoverImagePath;
        } elseif ($request->has('cover_image') && !$request->input('cover_image')) {
            $this->mediaStorage->delete($coverImagePath);
            $coverImagePath = null;
        }

        $bannerImagePath = $magazine->banner_image;
        if ($request->hasFile('banner_image')) {
            return response()->json(['message' => 'Raw browser uploads are disabled for publication banners. Use the direct S3 upload-session flow.'], 410);
        } elseif (!empty($validated['banner_image_upload_id'])) {
            $newBannerImagePath = app(CleanUploadResolver::class)->cleanKey($user, $validated['banner_image_upload_id'], 'publication_banner_image');
            if ($newBannerImagePath !== $bannerImagePath) {
                $this->mediaStorage->delete($bannerImagePath);
            }
            $bannerImagePath = $newBannerImagePath;
        } elseif ($request->has('banner_image') && !$request->input('banner_image')) {
            $this->mediaStorage->delete($bannerImagePath);
            $bannerImagePath = null;
        }

        $updateData = [
            'title' => $validated['title'],
            'cover_image' => $coverImagePath,
            'banner_image' => $bannerImagePath,
            'description' => $validated['description'] ?? null,
            'about_text' => $validated['about_text'] ?? null,
        ];

        if ($user->hasPermission('seo.magazines')) {
            $updateData['seo_title'] = $validated['seo_title'] ?? null;
            $updateData['seo_description'] = $validated['seo_description'] ?? null;
            $updateData['seo_keywords'] = $validated['seo_keywords'] ?? null;
        }

        $magazine->update($updateData);

        // Sync editors
        $editorUserIds = [];
        if ($request->has('editor_user_ids')) {
            $editorUserIds = (array) $request->input('editor_user_ids');
        } elseif ($request->has('editor_id')) {
            $editorId = $request->input('editor_id');
            if (!empty($editorId)) {
                $editorUserIds = [intval($editorId)];
            }
        }
        $magazine->editors()->sync($editorUserIds);

        return response()->json([
            'message' => 'Magazine updated successfully.',
            'magazine' => $magazine->load('editors')
        ]);
    }

    /**
     * DELETE /api/admin/magazines/{id}
     * Delete an existing magazine (Super Admin only).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can delete records.'], 403);
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
     * Update an existing sub-page (Admin or Super Admin only).
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
            'slug' => 'nullable|string|max:255',
            'content' => 'required|string',
            'sort_order' => 'integer',
            'status' => 'nullable|in:active,draft,private,inactive',
        ]);

        $page->update([
            'title' => $validated['title'],
            'slug' => $this->uniquePageSlug($magazineId, $validated['slug'] ?? $validated['title'], $page->id),
            'content' => $validated['content'],
            'sort_order' => $validated['sort_order'] ?? $page->sort_order,
            'status' => $validated['status'] ?? $page->status ?? 'active',
        ]);

        return response()->json([
            'message' => 'Magazine page updated successfully.',
            'page' => $page
        ]);
    }

    /**
     * DELETE /api/admin/magazines/{magazineId}/pages/{pageId}
     * Delete an existing sub-page (Super Admin only).
     */
    public function destroyPage(Request $request, int $magazineId, int $pageId): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            return response()->json(['message' => 'Forbidden. Only Super Admin can delete records.'], 403);
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

    private function publicMagazineQuery(string $slug, string $publicationType)
    {
        return Magazine::where('slug', $slug)->where('publication_type', $publicationType);
    }

    private function publicMagazinePayload(Magazine $magazine): array
    {
        return [
            'id' => $magazine->id,
            'title' => $magazine->title,
            'slug' => $magazine->slug,
            'cover_image' => $magazine->cover_image_url,
            'cover_image_url' => $magazine->cover_image_url,
            'main_image_url' => $magazine->main_image_url,
            'banner_image_url' => $magazine->banner_image_url,
            'description' => $magazine->description,
            'publication_type' => $magazine->publication_type,
            'publication_label' => $magazine->publicationTypeLabel(),
            'public_route_prefix' => $magazine->publicRoutePrefix(),
            'seo_title' => $magazine->seo_title ?: $magazine->title . ' | ScholarlyNest',
            'seo_description' => $magazine->seo_description ?: Str::limit(strip_tags((string) $magazine->description), 160, ''),
            'seo_keywords' => $magazine->seo_keywords ?: '',
            'og_image' => $magazine->cover_image_url,
        ];
    }

    private function requestedPublicationType(Request $request): string
    {
        $type = $request->query('publication_type') ?: $request->input('publication_type') ?: $request->route('publication_type');
        return in_array($type, [Magazine::TYPE_MAGAZINE, Magazine::TYPE_JOURNAL], true) ? $type : Magazine::TYPE_MAGAZINE;
    }

    private function applyPublicationTypeFilter($query, Request $request): void
    {
        $type = $request->query('publication_type');
        if ($type === 'all') {
            return;
        }
        if (!$type) {
            $type = $request->route('publication_type');
        }
        if (!$type) {
            if (str_contains($request->getPathInfo(), '/journals')) {
                $type = Magazine::TYPE_JOURNAL;
            } elseif (str_contains($request->getPathInfo(), '/magazines')) {
                $type = Magazine::TYPE_MAGAZINE;
            }
        }
        if (in_array($type, [Magazine::TYPE_MAGAZINE, Magazine::TYPE_JOURNAL], true)) {
            $query->where('publication_type', $type);
        }
    }

    private function publicShellPayload(Magazine $magazine): array
    {
        $specificPages = $magazine->pages()
            ->where('status', 'active')
            ->get(['id', 'magazine_id', 'title', 'slug', 'sort_order'])
            ->map(fn ($page) => array_merge($page->toArray(), ['is_shared' => false]));
        $specificSlugs = $specificPages->pluck('slug');
        $sharedPages = SharedPublicPage::query()->visibleFor($magazine)
            ->where('show_in_navigation', true)
            ->whereNotIn('slug', $specificSlugs)
            ->get(['id', 'title', 'slug', 'sort_order'])
            ->map(fn ($page) => array_merge($page->toArray(), ['is_shared' => true]));

        return array_merge($this->publicMagazinePayload($magazine), [
            'pages' => $specificPages->concat($sharedPages)
                ->sortBy(fn ($page) => sprintf('%010d-%s', $page['sort_order'], $page['title']))
                ->values(),
        ]);
    }

    private function magazineSeoPayload(Magazine $magazine, string $section): array
    {
        return [
            'title' => $section . ' | ' . ($magazine->seo_title ?: $magazine->title . ' | ScholarlyNest'),
            'description' => $magazine->seo_description ?: Str::limit(strip_tags((string) ($magazine->about_text ?: $magazine->description)), 160, ''),
            'keywords' => $magazine->seo_keywords ?: '',
            'og_image' => $magazine->cover_image_url,
        ];
    }

    private function publishedArticleQuery(Magazine $magazine)
    {
        return $magazine->articles()
            ->where('status', 'published')
            ->with([
                'user:id,name',
                'issue:id,volume_number,issue_number,special_title,issue_month,issue_year,published_at',
                'articleAuthors:id,article_id,co_author_name,author_order,is_owner,is_corresponding',
            ])
            ->orderByDesc('published_at')
            ->latest();
    }

    private function publicArticlePayload($article): array
    {
        $publicationDate = $this->tocPublicationDate($article);

        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'abstract' => $article->abstract,
            'doi' => $article->doi,
            'published_at' => $article->published_at,
            'published_year' => $publicationDate?->year,
            'published_month' => $publicationDate?->month,
            'published_month_name' => $publicationDate?->format('F'),
            'created_at' => $article->created_at,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'has_pdf' => !empty($article->pdf_path),
            'user' => $article->user ? [
                'id' => $article->user->id,
                'name' => $article->user->name,
            ] : null,
            'article_authors' => $article->articleAuthors
                ->sortBy('author_order')
                ->map(fn ($author) => [
                    'id' => $author->id,
                    'co_author_name' => $author->co_author_name,
                    'author_order' => $author->author_order,
                    'is_owner' => $author->is_owner,
                    'is_corresponding' => $author->is_corresponding,
                ])
                ->values(),
            'issue' => $article->issue ? [
                'id' => $article->issue->id,
                'volume_number' => $article->issue->volume_number,
                'issue_number' => $article->issue->issue_number,
                'issue_month' => $article->issue->issue_month,
                'issue_year' => $article->issue->issue_year,
                'special_title' => $article->issue->special_title,
                'published_at' => $article->issue->published_at,
            ] : null,
        ];
    }

    private function tocPublicationDate($article): ?\Carbon\Carbon
    {
        return $article->published_at ?: $article->created_at;
    }

    private function reservedPublicPageSlugs(): array
    {
        return ['about-and-overview', 'table-of-contents', 'articles', 'issues', 'pages', 'latest-articles', 'latest-published-articles'];
    }

    private function isReservedPublicPageSlug(?string $slug): bool
    {
        return in_array(Str::slug((string) $slug), $this->reservedPublicPageSlugs(), true);
    }

    private function uniquePageSlug(int $magazineId, string $source, ?int $ignorePageId = null): string
    {
        $base = Str::slug($source) ?: 'page';

        if ($this->isReservedPublicPageSlug($base)) {
            abort(response()->json(['message' => 'This page slug is reserved for a standard magazine route.'], 422));
        }

        $slug = $base;
        $counter = 2;

        while (MagazinePage::where('magazine_id', $magazineId)
            ->where('slug', $slug)
            ->when($ignorePageId, fn ($query) => $query->where('id', '!=', $ignorePageId))
            ->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function hasGlobalMagazineAccess($user): bool
    {
        return $user && ($user->hasRole('super_admin') || $user->hasRole('admin'));
    }

    private function assignedMagazineIds($user, array $roles): array
    {
        if (!$user) {
            return [];
        }

        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->unique()
            ->values()
            ->all();

        $query = \DB::table('magazine_user')
            ->join('magazines', 'magazines.id', '=', 'magazine_user.magazine_id')
            ->where('magazine_user.user_id', $user->id)
            ->where(function ($query) use ($normalizedRoles) {
                $query->whereIn('role', $normalizedRoles)
                    ->orWhereNull('role');
            })
            ->select('magazine_user.magazine_id');

        if ($user->isPublicationEditor()) {
            $query->whereIn('magazines.publication_type', $user->editorPublicationTypes());
        }

        return $query->pluck('magazine_user.magazine_id')
            ->unique()
            ->values()
            ->all();
    }

    private function isAssignedMagazineRole($user, int $magazineId, array $roles): bool
    {
        if (in_array('editor', $roles, true) && $user->isPublicationEditor()) {
            $type = Magazine::whereKey($magazineId)->value('publication_type');
            if (!in_array($type, $user->editorPublicationTypes(), true)) {
                return false;
            }
        }

        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->unique()
            ->values()
            ->all();

        return \DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where('magazine_id', $magazineId)
            ->where(function ($query) use ($normalizedRoles) {
                $query->whereIn('role', $normalizedRoles)
                    ->orWhereNull('role');
            })
            ->exists();
    }

    /**
     * PATCH /api/admin/magazines/{slug}/seo
     * SEO-only update.
     */
    public function updateSeo(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin') || !$user->hasPermission('seo.magazines')) {
            return response()->json(['message' => 'Unauthorized. Super Admin privileges required.'], 403);
        }

        $magazine = Magazine::where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'seo_title'       => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords'    => 'nullable|string|max:500',
        ]);

        $magazine->update($validated);

        return response()->json([
            'message' => 'Magazine SEO metadata updated successfully.',
            'magazine' => $magazine,
        ]);
    }
}
