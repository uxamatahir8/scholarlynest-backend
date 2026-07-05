<?php

namespace App\Http\Controllers;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Services\Media\MediaStorageService;
use App\Services\NotificationService;
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

        $user = $request->user('sanctum') ?: $request->user();
        if ($user && ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor'))) {
            $query->whereHas('editors', function($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }

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
        $magazines = Magazine::withCount(['articles' => function ($query) {
            $query->where('status', 'published');
        }])
        ->latest()
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

        if (!$this->hasGlobalMagazineAccess($user)) {
            $assignedMagazineIds = $this->assignedMagazineIds($user, ['editor', 'publisher', 'magazine_editor']);
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
     * Authenticated management detail scoped to assigned magazines and page ownership.
     */
    public function adminShow(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $magazine = Magazine::where('slug', $slug)
            ->with(['editors', 'pages' => function ($query) {
                $query->orderBy('sort_order', 'asc');
            }])
            ->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        if (!$this->hasGlobalMagazineAccess($user) && !$this->isAssignedMagazineRole($user, $magazine->id, ['editor', 'publisher', 'magazine_editor'])) {
            return response()->json(['message' => 'Forbidden. You are not assigned to this magazine.'], 403);
        }

        if ($this->isEditorOnly($user)) {
            $magazine->setRelation('pages', $magazine->pages
                ->filter(fn (MagazinePage $page) => (int) $page->created_by === (int) $user->id && (bool) $page->is_editor_created)
                ->values());
        } elseif (!$this->hasGlobalMagazineAccess($user) && $user->hasRole('publisher')) {
            $magazine->setRelation('pages', collect());
        }

        return response()->json($magazine);
    }

    /**
     * GET /api/magazines/{slug}
     * Returns only the public magazine shell and active navigation pages.
     */
    public function show(string $slug): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug)->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        return response()->json($this->publicShellPayload($magazine));
    }

    /**
     * GET /api/magazines/{slug}/about-and-overview
     * Returns public about/overview data for one magazine only.
     */
    public function aboutAndOverview(string $slug): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug)->first();

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
        $magazine = $this->publicMagazineQuery($slug)->first();

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
    public function tableOfContents(string $slug): JsonResponse
    {
        $magazine = $this->publicMagazineQuery($slug)->first();

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
    public function publicPage(string $slug, string $pageSlug): JsonResponse
    {
        if ($this->isReservedPublicPageSlug($pageSlug)) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $magazine = $this->publicMagazineQuery($slug)->first();

        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $page = $magazine->pages()
            ->where('slug', $pageSlug)
            ->where('status', 'active')
            ->first();

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
            ],
            'seo' => [
                'title' => $page->title . ' | ' . $magazine->title . ' | ScholarlyNest',
                'description' => Str::limit(strip_tags($page->content), 160, ''),
                'keywords' => $magazine->seo_keywords ?: '',
                'og_image' => $magazine->cover_image,
            ],
        ]);
    }

    /**
     * GET /api/magazines/{slug}/articles
     * Public paginated feed of published articles under a specific magazine.
     */
    public function articles(string $slug): JsonResponse
    {
        $magazine = Magazine::where('slug', $slug)->first();

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
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
            'editor_id' => 'nullable|integer|exists:users,id',
            'editor_user_ids' => 'nullable|array',
            'editor_user_ids.*' => 'integer|exists:users,id',
        ]);

        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            $coverImagePath = $this->mediaStorage->storeUploadedFile($request->file('cover_image'), 'covers');
        } elseif (is_string($request->input('cover_image'))) {
            $coverImagePath = $request->input('cover_image');
        }

        $slug = Str::slug($validated['title']) . '-' . Str::random(5);

        $magazineData = [
            'title' => $validated['title'],
            'slug' => $slug,
            'cover_image' => $coverImagePath,
            'description' => $validated['description'] ?? null,
            'about_text' => $validated['about_text'] ?? null,
        ];

        if ($user->hasPermission('seo.magazines')) {
            $magazineData['seo_title'] = $validated['seo_title'] ?? null;
            $magazineData['seo_description'] = $validated['seo_description'] ?? null;
            $magazineData['seo_keywords'] = $validated['seo_keywords'] ?? null;
        }

        $magazine = Magazine::create($magazineData);

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
            'magazine' => $magazine->load('editors')
        ], 211);
    }

    /**
     * POST /api/admin/magazines/{id}/pages
     * Create or update custom sub-pages for a magazine (Admin only).
     */
    public function storePage(Request $request, int $magazineId): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor'))) {
            return response()->json(['message' => 'Forbidden. Admin or assigned editor privileges required.'], 403);
        }

        $magazine = Magazine::find($magazineId);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        if ($user->hasRole('editor') && !$this->isAssignedMagazineRole($user, $magazine->id, ['editor', 'magazine_editor'])) {
            return response()->json(['message' => 'Forbidden. You are not assigned to this magazine.'], 403);
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
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
        ]);

        $coverImagePath = $magazine->cover_image;
        if ($request->hasFile('cover_image')) {
            $this->mediaStorage->delete($coverImagePath);
            $coverImagePath = $this->mediaStorage->storeUploadedFile($request->file('cover_image'), 'covers');
        } elseif ($request->has('cover_image')) {
            $coverImagePath = $request->input('cover_image');
        }

        $updateData = [
            'title' => $validated['title'],
            'cover_image' => $coverImagePath,
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
     * Delete an existing magazine (Admin only).
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
     * Update an existing sub-page (Admin only).
     */
    public function updatePage(Request $request, int $magazineId, int $pageId): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor'))) {
            return response()->json(['message' => 'Forbidden. Admin or assigned editor privileges required.'], 403);
        }

        $magazine = Magazine::find($magazineId);
        if (!$magazine) {
            return response()->json(['message' => 'Magazine not found.'], 404);
        }

        $page = $magazine->pages()->find($pageId);
        if (!$page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        if ($user->hasRole('editor') && (
            !$this->isAssignedMagazineRole($user, $magazine->id, ['editor', 'magazine_editor'])
            || (int) $page->created_by !== (int) $user->id
            || !$page->is_editor_created
        )) {
            return response()->json(['message' => 'Forbidden. Editors can edit only their own pages for assigned magazines.'], 403);
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
     * Delete an existing sub-page (Admin only).
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

    private function publicMagazineQuery(string $slug)
    {
        return Magazine::where('slug', $slug);
    }

    private function publicMagazinePayload(Magazine $magazine): array
    {
        return [
            'id' => $magazine->id,
            'title' => $magazine->title,
            'slug' => $magazine->slug,
            'cover_image' => $magazine->cover_image,
            'cover_image_url' => $magazine->cover_image_url,
            'description' => $magazine->description,
            'seo_title' => $magazine->seo_title ?: $magazine->title . ' | ScholarlyNest',
            'seo_description' => $magazine->seo_description ?: Str::limit(strip_tags((string) $magazine->description), 160, ''),
            'seo_keywords' => $magazine->seo_keywords ?: '',
            'og_image' => $magazine->cover_image_url,
        ];
    }

    private function publicShellPayload(Magazine $magazine): array
    {
        return array_merge($this->publicMagazinePayload($magazine), [
            'pages' => $magazine->pages()
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->get(['id', 'magazine_id', 'title', 'slug', 'sort_order'])
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

    private function isEditorOnly($user): bool
    {
        return $user
            && !$this->hasGlobalMagazineAccess($user)
            && ($user->hasRole('editor') || $user->hasRole('magazine_editor') || $user->hasRole('magazine-editor'));
    }

    private function assignedMagazineIds($user, array $roles): array
    {
        if (!$user) {
            return [];
        }

        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->when(in_array('magazine_editor', $roles, true), fn ($collection) => $collection->push('editor'))
            ->unique()
            ->values()
            ->all();

        return \DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where(function ($query) use ($normalizedRoles) {
                $query->whereIn('role', $normalizedRoles)
                    ->orWhereNull('role');
            })
            ->pluck('magazine_id')
            ->unique()
            ->values()
            ->all();
    }

    private function isAssignedMagazineRole($user, int $magazineId, array $roles): bool
    {
        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->when(in_array('magazine_editor', $roles, true), fn ($collection) => $collection->push('editor'))
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
        if (!$user || !$user->hasPermission('seo.magazines')) {
            return response()->json(['message' => 'Unauthorized. SEO permission required.'], 403);
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
