<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Magazine;
use App\Services\PdfGeneratorService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ArticleController extends Controller
{
    protected PdfGeneratorService $pdfService;
    protected NotificationService $notificationService;

    public function __construct(PdfGeneratorService $pdfService, NotificationService $notificationService)
    {
        $this->pdfService = $pdfService;
        $this->notificationService = $notificationService;
    }

    public function show(string $slug): JsonResponse
    {
        $article = Article::where('slug', $slug)
            ->with(['magazine:id,title,slug,cover_image', 'user:id,name,email,created_at', 'tags', 'articleAuthors'])
            ->first();

        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // If the article is not approved, authorize the viewer via ArticlePolicy
        if ($article->status === 'pending' || $article->status === 'rejected') {
            $user = request()->user('sanctum');
            if (!$user) {
                // Hide the page entirely for unauthorized public viewers
                return response()->json(['message' => 'Article not found.'], 404);
            }
            if ($user->cannot('view', $article)) {
                return response()->json(['message' => 'This action is unauthorized.'], 403);
            }
        }

        // Increment impressions on view
        $article->increment('impressions');

        // Fetch author user metrics (total papers published)
        $authorApprovedCount = Article::where('user_id', $article->user_id)
            ->where('status', 'approved')
            ->count();

        // Map metrics payload
        $authorMetrics = [
            'total_papers_approved' => $authorApprovedCount,
            'member_since' => $article->user->created_at->format('M Y'),
        ];

        // Fetch adjacent articles
        $publishedAt = $article->published_at ?? $article->created_at;

        $previousArticle = Article::where('magazine_id', $article->magazine_id)
            ->where('status', 'approved')
            ->where(function($query) use ($publishedAt) {
                $query->where('published_at', '<', $publishedAt)
                      ->orWhere(function($q) use ($publishedAt) {
                          $q->whereNull('published_at')->where('created_at', '<', $publishedAt);
                      });
            })
            ->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        $nextArticle = Article::where('magazine_id', $article->magazine_id)
            ->where('status', 'approved')
            ->where(function($query) use ($publishedAt) {
                $query->where('published_at', '>', $publishedAt)
                      ->orWhere(function($q) use ($publishedAt) {
                          $q->whereNull('published_at')->where('created_at', '>', $publishedAt);
                      });
            })
            ->orderBy('published_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->first();

        $articleData = $article->toArray();
        $articleData['seo_title'] = $article->seo_title ?: $article->title . ' | ' . ($article->magazine?->title ?? 'ScholarlyNest');
        $articleData['seo_description'] = $article->seo_description ?: Str::limit(strip_tags($article->abstract), 160, '');
        $articleData['seo_keywords'] = $article->seo_keywords ?: $article->tags->pluck('name')->implode(', ');
        $articleData['og_image'] = ($article->magazine && $article->magazine->cover_image) ? $article->magazine->cover_image : null;

        $articleData['previous_article_slug'] = $previousArticle?->slug;
        $articleData['next_article_slug'] = $nextArticle?->slug;
        $articleData['previous_article_title'] = $previousArticle?->title;
        $articleData['next_article_title'] = $nextArticle?->title;

        return response()->json([
            'article' => $articleData,
            'author_metrics' => $authorMetrics,
            'previous_article_slug' => $previousArticle?->slug,
            'next_article_slug' => $nextArticle?->slug,
            'previous_article_title' => $previousArticle?->title,
            'next_article_title' => $nextArticle?->title,
        ]);
    }

    /**
     * GET /api/articles/latest
     * Fetch a list of recently approved articles across all magazines.
     */
    public function latest(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 6);
        
        $articles = Article::where('status', 'approved')
            ->with(['magazine:id,title,slug', 'user:id,name'])
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $articles
        ]);
    }

    /**
     * POST /api/articles/{id}/click
     * Track a click on an article.
     */
    public function trackClick(int $id): JsonResponse
    {
        $article = Article::find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        $article->increment('clicks');

        return response()->json([
            'message' => 'Click tracked successfully.',
            'clicks' => $article->clicks
        ]);
    }

    /**
     * POST /api/articles/{id}/share-click
     * Track a social share button click.
     */
    public function trackShareClick(Request $request, int $id): JsonResponse
    {
        $article = Article::find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        $validated = $request->validate([
            'platform' => 'required|string|max:50',
        ]);

        $shareClick = \App\Models\ArticleShareClick::firstOrCreate(
            [
                'article_id' => $article->id,
                'platform' => $validated['platform'],
            ],
            [
                'clicks' => 0
            ]
        );

        $shareClick->increment('clicks');

        return response()->json([
            'message' => 'Share click tracked successfully.',
            'platform' => $shareClick->platform,
            'clicks' => $shareClick->clicks,
        ]);
    }

    /**
     * POST /api/articles
     * Submits a user article. Handles both optional PDF storage or flags for dynamic PDF building.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'magazine_id' => 'required|exists:magazines,id',
            'title' => 'required|string|max:255',
            'abstract' => 'required|string',
            'full_text' => 'required|string',
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240', // max 10MB
            'tags' => 'nullable',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
            'co_authors' => 'nullable|string',
        ]);

        // Decode co_authors stringified JSON
        $coAuthors = is_string($request->input('co_authors'))
            ? (json_decode($request->input('co_authors'), true) ?: [])
            : ($request->input('co_authors') ?: []);

        // Validate co_authors array structure
        $coAuthorsValidator = \Validator::make(['co_authors' => $coAuthors], [
            'co_authors' => 'array',
            'co_authors.*.name' => 'required|string|max:255',
            'co_authors.*.email' => 'required|email|max:255',
            'co_authors.*.can_edit' => 'boolean',
            'co_authors.*.create_account' => 'boolean',
        ]);

        if ($coAuthorsValidator->fails()) {
            return response()->json([
                'message' => 'Validation failed for co-authors.',
                'errors' => $coAuthorsValidator->errors()
            ], 422);
        }

        // Validate that primary author does not list themselves
        foreach ($coAuthors as $coAuthor) {
            if (strtolower(trim($coAuthor['email'])) === strtolower(trim($user->email))) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['co_authors' => ['You cannot list yourself as a co-author.']]
                ], 422);
            }
        }

        $pdfPath = null;
        if ($request->hasFile('pdf_file')) {
            $path = $request->file('pdf_file')->store('manuscripts', 'public');
            $pdfPath = 'storage/' . $path;
        }

        $slug = Str::slug($validated['title']) . '-' . Str::random(6);

        $articleData = [
            'magazine_id' => $validated['magazine_id'],
            'user_id' => $user->id,
            'title' => $validated['title'],
            'slug' => $slug,
            'abstract' => $validated['abstract'],
            'full_text' => $validated['full_text'],
            'pdf_path' => $pdfPath,
            'status' => 'pending',
        ];

        if ($user->hasPermission('seo.articles')) {
            $articleData['seo_title'] = $validated['seo_title'] ?? null;
            $articleData['seo_description'] = $validated['seo_description'] ?? null;
            $articleData['seo_keywords'] = $validated['seo_keywords'] ?? null;
        }

        $coAuthorsData = [];

        $article = \DB::transaction(function() use ($articleData, $request, $coAuthors, $user, &$coAuthorsData) {
            $article = Article::create($articleData);
            $this->syncTags($article, $request->input('tags'));

            foreach ($coAuthors as $coAuthor) {
                $email = strtolower(trim($coAuthor['email']));
                $name = trim($coAuthor['name']);
                $canEdit = (bool)($coAuthor['can_edit'] ?? false);
                $createAccount = (bool)($coAuthor['create_account'] ?? false);

                $existingUser = \App\Models\User::where('email', $email)->first();
                $userId = null;
                $accountProvisioned = false;
                $tempPassword = null;

                if ($existingUser) {
                    $userId = $existingUser->id;
                    $accountProvisioned = false;
                } elseif ($createAccount) {
                    $tempPassword = \Illuminate\Support\Str::random(12);
                    $defaultRoleName = \App\Models\Setting::where('key', 'default_registration_role')->value('value') ?? 'author';
                    $defaultRole = \App\Models\Role::where('name', $defaultRoleName)->first();

                    $newUser = \App\Models\User::create([
                        'name' => $name,
                        'email' => $email,
                        'password' => \Illuminate\Support\Facades\Hash::make($tempPassword),
                        'needs_password_reset' => true,
                        'email_verified_at' => now(),
                        'role_id' => $defaultRole?->id,
                    ]);

                    $userId = $newUser->id;
                    $accountProvisioned = true;
                }

                \App\Models\ArticleAuthor::create([
                    'article_id' => $article->id,
                    'user_id' => $userId,
                    'co_author_name' => $name,
                    'co_author_email' => $email,
                    'can_edit' => $canEdit,
                    'account_provisioned' => $accountProvisioned,
                ]);

                $coAuthorItem = [
                    'name' => $name,
                    'email' => $email,
                    'can_edit' => $canEdit,
                    'create_account' => $createAccount,
                    'user_id' => $userId,
                    'account_provisioned' => $accountProvisioned,
                ];
                if ($tempPassword) {
                    $coAuthorItem['temporary_password'] = $tempPassword;
                }
                $coAuthorsData[] = $coAuthorItem;
            }

            return $article;
        });

        // Dispatch synchronized queued notifications
        event(new \App\Events\ArticleSubmitted($article, $coAuthorsData));

        return response()->json([
            'message' => 'Your research article has been submitted successfully for peer review.',
            'article' => $article->load(['tags', 'articleAuthors'])
        ], 211);
    }

    /**
     * GET /api/admin/articles
     * List articles for admin review panel (Admin only).
     */
    public function adminList(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $status = $request->query('status');
        $query = Article::with(['magazine:id,title', 'user:id,name,email', 'tags', 'shareClicks']);

        // Scope to user's own articles if not an admin/editor
        if (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor')) {
            $query->where('user_id', $user->id);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($request->filled('magazine_id') && $request->query('magazine_id') !== 'all') {
            $query->where('magazine_id', $request->query('magazine_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('abstract', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('tags', function ($tq) use ($search) {
                      $tq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = $request->integer('per_page', 25);
        $articles = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($articles);
    }

    /**
     * PATCH /api/admin/articles/{id}/review
     * Admin operations endpoint to approve or reject articles.
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (
            !$user->hasRole('super_admin') &&
            !$user->hasRole('admin') &&
            !$user->hasRole('editor') &&
            !$user->hasPermission('articles.approve') &&
            !$user->hasPermission('articles.auto-approve')
        )) {
            return response()->json(['message' => 'Forbidden. Admin/Editor/Approve privileges required.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'rejection_reason' => 'required_if:status,rejected|nullable|string',
        ]);

        $article = Article::with('user')->find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        $oldStatus = $article->status;
        $article->status = $validated['status'];
        if ($validated['status'] === 'rejected') {
            $article->rejection_reason = $validated['rejection_reason'];
        } else {
            $article->rejection_reason = null;
            if ($validated['status'] === 'approved' && !$article->published_at) {
                $article->published_at = now();
            }
        }

        // If approved and pdf_path is empty, compile and generate a clean dynamic PDF download
        if ($validated['status'] === 'approved' && empty($article->pdf_path)) {
            try {
                $generatedPdfUrl = $this->pdfService->generate($article);
                $article->pdf_path = $generatedPdfUrl;
            } catch (\Exception $e) {
                // Return descriptive message if PDF rendering engine fails
                return response()->json([
                    'message' => 'Article status approved, but dynamic PDF generation failed: ' . $e->getMessage(),
                    'error' => $e->getTraceAsString()
                ], 500);
            }
        }

        $article->save();

        if ($article->status === 'approved' && $oldStatus !== 'approved') {
            $this->sendArticleNewsletter($article);
        }

        return response()->json([
            'message' => 'Article status updated successfully.',
            'article' => $article
        ]);
    }

    /**
     * GET /api/admin/articles/{id}
     * Fetch a single article by ID.
     */
    public function showById(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $article = Article::with(['tags', 'magazine', 'shareClicks', 'articleAuthors'])->find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // Authorize via ArticlePolicy
        if ($user->cannot('view', $article)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($article);
    }

    /**
     * PUT /api/admin/articles/{id}
     * Update article metadata and content.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $article = Article::find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // Authorize via ArticlePolicy
        if ($user->cannot('update', $article)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'magazine_id' => 'required|exists:magazines,id',
            'title' => 'required|string|max:255',
            'abstract' => 'required|string',
            'full_text' => 'required|string',
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240', // max 10MB
            'status' => 'nullable|in:pending,approved,rejected',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
            'co_authors' => 'nullable|string',
        ]);

        // Decode co_authors stringified JSON
        $coAuthors = is_string($request->input('co_authors'))
            ? (json_decode($request->input('co_authors'), true) ?: [])
            : ($request->input('co_authors') ?: []);

        // Validate co_authors array structure
        $coAuthorsValidator = \Validator::make(['co_authors' => $coAuthors], [
            'co_authors' => 'array',
            'co_authors.*.name' => 'required|string|max:255',
            'co_authors.*.email' => 'required|email|max:255',
            'co_authors.*.can_edit' => 'boolean',
            'co_authors.*.create_account' => 'boolean',
        ]);

        if ($coAuthorsValidator->fails()) {
            return response()->json([
                'message' => 'Validation failed for co-authors.',
                'errors' => $coAuthorsValidator->errors()
            ], 422);
        }

        // Validate that primary author does not list themselves
        foreach ($coAuthors as $coAuthor) {
            if (strtolower(trim($coAuthor['email'])) === strtolower(trim($user->email))) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['co_authors' => ['You cannot list yourself as a co-author.']]
                ], 422);
            }
        }

        $pdfPath = $article->pdf_path;
        if ($request->hasFile('pdf_file')) {
            if ($pdfPath) {
                $oldPath = str_replace('storage/', '', $pdfPath);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('pdf_file')->store('manuscripts', 'public');
            $pdfPath = 'storage/' . $path;
        }

        $slug = $article->slug;
        if ($validated['title'] !== $article->title) {
            $slug = Str::slug($validated['title']) . '-' . Str::random(6);
        }

        // Restrict status edits to admins/editors only
        $oldStatus = $article->status;
        $status = $article->status;
        if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor')) {
            $status = $validated['status'] ?? $article->status;
        }

        $publishedAt = $article->published_at;
        if ($status === 'approved' && !$publishedAt) {
            $publishedAt = now();
        }

        $updateData = [
            'magazine_id' => $validated['magazine_id'],
            'title' => $validated['title'],
            'slug' => $slug,
            'abstract' => $validated['abstract'],
            'full_text' => $validated['full_text'],
            'pdf_path' => $pdfPath,
            'status' => $status,
            'published_at' => $publishedAt,
        ];

        if ($user->hasPermission('seo.articles')) {
            $updateData['seo_title'] = $validated['seo_title'] ?? null;
            $updateData['seo_description'] = $validated['seo_description'] ?? null;
            $updateData['seo_keywords'] = $validated['seo_keywords'] ?? null;
        }

        $coAuthorsData = [];

        \DB::transaction(function() use ($article, $updateData, $request, $coAuthors, $user, &$coAuthorsData) {
            $article->update($updateData);
            $this->syncTags($article, $request->input('tags'));

            // Refresh co-authors relation
            $article->articleAuthors()->delete();

            foreach ($coAuthors as $coAuthor) {
                $email = strtolower(trim($coAuthor['email']));
                $name = trim($coAuthor['name']);
                $canEdit = (bool)($coAuthor['can_edit'] ?? false);
                $createAccount = (bool)($coAuthor['create_account'] ?? false);

                $existingUser = \App\Models\User::where('email', $email)->first();
                $userId = null;
                $accountProvisioned = false;
                $tempPassword = null;

                if ($existingUser) {
                    $userId = $existingUser->id;
                    $accountProvisioned = false;
                } elseif ($createAccount) {
                    $tempPassword = \Illuminate\Support\Str::random(12);
                    $defaultRoleName = \App\Models\Setting::where('key', 'default_registration_role')->value('value') ?? 'author';
                    $defaultRole = \App\Models\Role::where('name', $defaultRoleName)->first();

                    $newUser = \App\Models\User::create([
                        'name' => $name,
                        'email' => $email,
                        'password' => \Illuminate\Support\Facades\Hash::make($tempPassword),
                        'needs_password_reset' => true,
                        'email_verified_at' => now(),
                        'role_id' => $defaultRole?->id,
                    ]);

                    $userId = $newUser->id;
                    $accountProvisioned = true;
                }

                \App\Models\ArticleAuthor::create([
                    'article_id' => $article->id,
                    'user_id' => $userId,
                    'co_author_name' => $name,
                    'co_author_email' => $email,
                    'can_edit' => $canEdit,
                    'account_provisioned' => $accountProvisioned,
                ]);

                $coAuthorItem = [
                    'name' => $name,
                    'email' => $email,
                    'can_edit' => $canEdit,
                    'create_account' => $createAccount,
                    'user_id' => $userId,
                    'account_provisioned' => $accountProvisioned,
                ];
                if ($tempPassword) {
                    $coAuthorItem['temporary_password'] = $tempPassword;
                }
                $coAuthorsData[] = $coAuthorItem;
            }
        });

        // Dispatch synchronized queued notifications
        event(new \App\Events\ArticleSubmitted($article, $coAuthorsData));

        // If approved and pdf_path is empty, generate dynamic PDF
        if ($article->status === 'approved' && empty($article->pdf_path)) {
            try {
                $generatedPdfUrl = $this->pdfService->generate($article);
                $article->pdf_path = $generatedPdfUrl;
                $article->save();
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Article updated successfully, but PDF generation failed: ' . $e->getMessage()
                ], 500);
            }
        }

        if ($article->status === 'approved' && $oldStatus !== 'approved') {
            $this->sendArticleNewsletter($article);
        }

        return response()->json([
            'message' => 'Article updated successfully.',
            'article' => $article->load(['tags', 'articleAuthors'])
        ]);
    }

    /**
     * Sync tags onto the article, auto-creating string tags in the process.
     */
    protected function syncTags(Article $article, $tagsInput)
    {
        if (empty($tagsInput)) {
            $article->tags()->sync([]);
            return;
        }

        if (is_string($tagsInput)) {
            $decoded = json_decode($tagsInput, true);
            if (is_array($decoded)) {
                $tagsInput = $decoded;
            } else {
                $tagsInput = array_filter(array_map('trim', explode(',', $tagsInput)));
            }
        }

        if (!is_array($tagsInput)) {
            return;
        }

        $tagIds = [];
        foreach ($tagsInput as $item) {
            if (is_numeric($item)) {
                $tagIds[] = (int) $item;
            } elseif (is_string($item) && trim($item) !== '') {
                $tag = \App\Models\Tag::firstOrCreate([
                    'magazine_id' => $article->magazine_id,
                    'name' => trim($item)
                ]);
                $tagIds[] = $tag->id;
            }
        }

        $article->tags()->sync($tagIds);
    }

    /**
     * GET /api/admin/stats
     * Aggregated statistics for the admin dashboard panel.
     */
    public function adminStats(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $totalArticles = Article::count();
        $pendingArticles = Article::where('status', 'pending')->count();
        $approvedArticles = Article::where('status', 'approved')->count();
        $rejectedArticles = Article::where('status', 'rejected')->count();

        $totalMagazines = \App\Models\Magazine::count();
        $totalUsers = \App\Models\User::count();

        $totalClicks = Article::sum('clicks');
        $totalImpressions = Article::sum('impressions');

        // Top articles by engagement
        $topArticles = Article::with(['magazine:id,title', 'user:id,name'])
            ->orderByRaw('(clicks + impressions) DESC')
            ->limit(5)
            ->get();

        return response()->json([
            'articles_count' => [
                'total' => $totalArticles,
                'pending' => $pendingArticles,
                'approved' => $approvedArticles,
                'rejected' => $rejectedArticles,
            ],
            'magazines_count' => $totalMagazines,
            'users_count' => $totalUsers,
            'analytics' => [
                'total_clicks' => $totalClicks,
                'total_impressions' => $totalImpressions,
                'ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
            ],
            'top_articles' => $topArticles,
        ]);
    }

    /**
     * Download or stream the article PDF directly via PHP to bypass web server 403 blocks.
     */
    public function downloadPdf($id)
    {
        $article = Article::findOrFail($id);

        if (empty($article->pdf_path)) {
            return response()->json(['error' => 'No PDF file is associated with this article.'], 404);
        }

        // Extract the relative path from 'storage/manuscripts/abc.pdf' -> 'manuscripts/abc.pdf'
        $path = str_replace('storage/', '', $article->pdf_path);

        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'The PDF file could not be found on storage.'], 404);
        }

        $absolutePath = Storage::disk('public')->path($path);

        return response()->file($absolutePath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($article->pdf_path) . '"'
        ]);
    }

    /**
     * Dispatch newsletter notification when a new article is approved/published.
     */
    private function sendArticleNewsletter(Article $article): void
    {
        try {
            $article->load(['magazine', 'user']);
            $magazine = $article->magazine;
            $subscribers = \App\Models\NewsletterSubscriber::where('is_active', true)->get();
            $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'https://dev.scholarlynest.com'), '/');

            $authorName = $article->user ? e($article->user->name) : 'ScholarlyNest Author';
            $magazineTitle = $magazine ? e($magazine->title) : 'Scientific Issue';
            $magazineSlug = $magazine ? $magazine->slug : '';
            $articleUrl = "{$frontendUrl}/magazines/{$magazineSlug}/articles/{$article->slug}";

            foreach ($subscribers as $sub) {
                $unsubscribeUrl = "{$frontendUrl}/unsubscribe/{$sub->token}";
                
                $bodyLines = [
                    '<span style="font-size: 11px; font-weight: bold; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 8px;">New Publication</span>',
                    'Published in <em>' . $magazineTitle . '</em> • By ' . $authorName,
                ];

                if ($article->abstract) {
                    $bodyLines[] = '<h4 style="font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #18181b; margin-bottom: 8px;">Abstract</h4>' . e($article->abstract);
                }

                $action = [
                    'text' => 'Read Full Article',
                    'url' => $articleUrl,
                ];

                $this->notificationService->send(
                    $sub->email,
                    "New Article Published: " . $article->title,
                    $article->title,
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
            logger()->error("Error sending article newsletter announcement: " . $e->getMessage());
        }
    }

    /**
     * PATCH /api/admin/articles/{id}/seo
     * SEO-only update with ownership checking.
     */
    public function updateSeo(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('seo.articles')) {
            return response()->json(['message' => 'Unauthorized. SEO permission required.'], 403);
        }

        $article = Article::findOrFail($id);

        // Ownership-aware scoping:
        // If user has edit-own (but NOT edit-any), they can only update SEO on their own articles.
        // If the user has a dedicated SEO role and NO article editing permissions at all, they edit all articles.
        $hasEditAny = $user->hasPermission('articles.edit-any');
        $hasEditOwn = $user->hasPermission('articles.edit-own');

        if ($hasEditOwn && !$hasEditAny && $article->user_id !== $user->id) {
            return response()->json(['message' => 'You can only manage SEO for your own articles.'], 403);
        }

        $validated = $request->validate([
            'seo_title'       => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords'    => 'nullable|string|max:500',
        ]);

        $article->update($validated);

        return response()->json([
            'message' => 'Article SEO metadata updated successfully.',
            'article' => $article,
        ]);
    }
}
