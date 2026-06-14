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

    public function show(string $idOrSlug): JsonResponse
    {
        $query = Article::with(['magazine:id,title,slug,cover_image', 'user:id,name,email,created_at', 'tags', 'articleAuthors', 'assets']);

        if (is_numeric($idOrSlug)) {
            $article = $query->find((int)$idOrSlug);
        } else {
            $article = $query->where('slug', $idOrSlug)->first();
        }

        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // submitted -> under_review trigger on first administrative/editorial view
        if ($article->status === 'submitted') {
            $user = request()->user('sanctum');
            if ($user) {
                $isAdminOrEditor = $user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor');
                if (!$isAdminOrEditor && ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor'))) {
                    $isAdminOrEditor = \DB::table('magazine_user')
                        ->where('user_id', $user->id)
                        ->where('magazine_id', $article->magazine_id)
                        ->exists();
                }
                if ($isAdminOrEditor) {
                    $article->status = 'under_review';
                    $article->saveQuietly();
                }
            }
        }

        // If the article is not published, authorize the viewer via ArticlePolicy
        if ($article->status !== 'published') {
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
            ->where('status', 'published')
            ->count();

        // Map metrics payload
        $authorMetrics = [
            'total_papers_approved' => $authorApprovedCount,
            'member_since' => $article->user->created_at->format('M Y'),
        ];

        // Fetch adjacent articles
        $publishedAt = $article->published_at ?? $article->created_at;

        $previousArticle = Article::where('magazine_id', $article->magazine_id)
            ->where('status', 'published')
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
            ->where('status', 'published')
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

        $articleData['previous_article_id'] = $previousArticle?->id;
        $articleData['next_article_id'] = $nextArticle?->id;
        $articleData['previous_article_slug'] = $previousArticle?->slug;
        $articleData['next_article_slug'] = $nextArticle?->slug;
        $articleData['previous_article_title'] = $previousArticle?->title;
        $articleData['next_article_title'] = $nextArticle?->title;

        return response()->json([
            'article' => $articleData,
            'author_metrics' => $authorMetrics,
            'previous_article_id' => $previousArticle?->id,
            'next_article_id' => $nextArticle?->id,
            'previous_article_slug' => $previousArticle?->slug,
            'next_article_slug' => $nextArticle?->slug,
            'previous_article_title' => $previousArticle?->title,
            'next_article_title' => $nextArticle?->title,
        ]);
    }

    /**
     * GET /api/articles/latest
     * Fetch a list of recently published articles across all magazines.
     */
    public function latest(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 6);
        
        $articles = Article::where('status', 'published')
            ->with(['magazine:id,title,slug,cover_image', 'user:id,name'])
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
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
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
            'co_authors.*.university_name' => 'nullable|string|max:255',
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

        $featuredImagePath = null;
        if ($request->hasFile('featured_image')) {
            $path = $request->file('featured_image')->store('articles', 'public');
            $featuredImagePath = 'storage/' . $path;
        }

        $slug = Str::slug($validated['title']);

        $articleData = [
            'magazine_id' => $validated['magazine_id'],
            'user_id' => $user->id,
            'title' => $validated['title'],
            'slug' => $slug,
            'abstract' => $validated['abstract'],
            'full_text' => $validated['full_text'],
            'pdf_path' => $pdfPath,
            'featured_image' => $featuredImagePath,
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
                        'university_name' => $coAuthor['university_name'] ?? null,
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
                    'university_name' => $coAuthor['university_name'] ?? null,
                ]);

                $coAuthorItem = [
                    'name' => $name,
                    'email' => $email,
                    'university_name' => $coAuthor['university_name'] ?? null,
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
        $query = Article::with(['magazine:id,title,slug,cover_image', 'user:id,name,email', 'tags', 'shareClicks']);

        // Scope to user's own articles if not an admin/editor, but bypass for magazine_editors
        $isEditorial = $user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor');
        if (!$isEditorial) {
            if ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) {
                $magazineIds = \DB::table('magazine_user')
                    ->where('user_id', $user->id)
                    ->pluck('magazine_id')
                    ->toArray();
                
                $query->whereIn('magazine_id', $magazineIds);
            } else {
                $query->where('user_id', $user->id);
            }
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
        
        $isEditorial = $user && (
            $user->hasRole('super_admin') ||
            $user->hasRole('admin') ||
            $user->hasRole('editor')
        );

        $article = Article::with('user')->find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        $isMagazineEditor = $user && ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor'));
        if ($isMagazineEditor) {
            // 1. Must be assigned to the magazine
            $isAssigned = \DB::table('magazine_user')
                ->where('user_id', $user->id)
                ->where('magazine_id', $article->magazine_id)
                ->exists();

            if (!$isAssigned) {
                return response()->json(['message' => 'Forbidden. You are not assigned to this magazine.'], 403);
            }

            // 2. Cannot transition to 'published'
            if ($request->input('status') === 'published') {
                return response()->json(['message' => 'Forbidden. Magazine editors cannot publish articles directly.'], 403);
            }
        } elseif (!$isEditorial && !$user->hasPermission('articles.approve') && !$user->hasPermission('articles.auto-approve')) {
            return response()->json(['message' => 'Forbidden. Editorial privileges required.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:approved,published,minor_review_rejected,fully_rejected',
            'rejection_reason' => 'required_if:status,minor_review_rejected,fully_rejected|nullable|string',
            'published_year' => 'required_if:status,published|nullable|integer|min:2000|max:2026',
            'published_month' => 'required_if:status,published|nullable|string|max:50',
        ]);

        $oldStatus = $article->status;
        $article->status = $validated['status'];
        
        if (in_array($validated['status'], ['minor_review_rejected', 'fully_rejected'])) {
            $article->rejection_reason = $validated['rejection_reason'];
            $article->published_year = null;
            $article->published_month = null;
        } else {
            $article->rejection_reason = null;
            if ($validated['status'] === 'published') {
                $article->published_year = $validated['published_year'];
                $article->published_month = $validated['published_month'];
            }
            if (!$article->published_at) {
                $article->published_at = now();
            }
        }

        // If approved/published and pdf_path is empty, compile and generate a clean dynamic PDF download
        if (in_array($validated['status'], ['approved', 'published']) && empty($article->pdf_path)) {
            try {
                $generatedPdfUrl = $this->pdfService->generate($article);
                $article->pdf_path = $generatedPdfUrl;
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Article status updated, but dynamic PDF generation failed: ' . $e->getMessage(),
                    'error' => $e->getTraceAsString()
                ], 500);
            }
        }

        $article->save();

        // Send newsletter announcement when advanced to published
        if ($article->status === 'published' && $oldStatus !== 'published') {
            $this->sendArticleNewsletter($article);
        }

        // Dispatch queued Laravel Mailable to author for rejections
        if (in_array($article->status, ['minor_review_rejected', 'fully_rejected'])) {
            \Illuminate\Support\Facades\Mail::to($article->user->email)->queue(
                new \App\Mail\ArticleRejectedMail($article, $article->status, $article->rejection_reason)
            );
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

        $article = Article::with(['tags', 'magazine', 'shareClicks', 'articleAuthors', 'assets'])->find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // Authorize via ArticlePolicy
        if ($user->cannot('view', $article)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // first-read trigger: transition 'submitted' to 'under_review' on admin/editor view
        if ($article->status === 'submitted') {
            $isAdminOrEditor = $user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor');
            if (!$isAdminOrEditor && ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor'))) {
                $isAdminOrEditor = \DB::table('magazine_user')
                    ->where('user_id', $user->id)
                    ->where('magazine_id', $article->magazine_id)
                    ->exists();
            }
            if ($isAdminOrEditor) {
                $article->status = 'under_review';
                $article->saveQuietly();
            }
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

        // Check if user has editorial privileges
        $isEditorial = $user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor');

        // Authorize via ArticlePolicy
        if ($user->cannot('update', $article)) {
            if (!$isEditorial && in_array($article->status, ['resubmitted', 'under_review', 'published'])) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'status' => ["You cannot edit this article because it is currently {$article->status}."]
                    ]
                ], 422);
            }
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'magazine_id' => 'required|exists:magazines,id',
            'title' => 'required|string|max:255',
            'abstract' => 'required|string',
            'full_text' => 'required|string',
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240', // max 10MB
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'delete_featured_image' => 'nullable|string', // multipart forms present booleans as strings sometimes
            'status' => 'nullable|in:submitted,under_review,approved,published,minor_review_rejected,fully_rejected,resubmitted',
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
            'co_authors.*.university_name' => 'nullable|string|max:255',
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

        $featuredImagePath = $article->featured_image;
        if ($request->input('delete_featured_image') === 'true' || $request->input('delete_featured_image') === '1') {
            if ($featuredImagePath) {
                $oldPath = str_replace('storage/', '', $featuredImagePath);
                Storage::disk('public')->delete($oldPath);
                $featuredImagePath = null;
            }
        }
        if ($request->hasFile('featured_image')) {
            if ($featuredImagePath) {
                $oldPath = str_replace('storage/', '', $featuredImagePath);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('featured_image')->store('articles', 'public');
            $featuredImagePath = 'storage/' . $path;
        }

        $slug = $article->slug;
        if ($validated['title'] !== $article->title) {
            $slug = Str::slug($validated['title']);
        }

        // Restrict status edits to admins/editors only or handle resubmission
        $oldStatus = $article->status;
        $status = $article->status;

        $isEditorial = $user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('editor') || 
            (($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) && 
             \DB::table('magazine_user')->where('user_id', $user->id)->where('magazine_id', $article->magazine_id)->exists());

        if (!$isEditorial) {
            // Authors saving edits must reset the status to 'resubmitted', and only allowed if currently minor_review_rejected
            if ($article->status !== 'minor_review_rejected') {
                return response()->json([
                    'message' => "Modifying this manuscript is locked. Current status: {$article->status}."
                ], 422);
            }
            $status = 'resubmitted';
        } else {
            $status = $validated['status'] ?? $article->status;
        }

        $publishedYear = $article->published_year;
        $publishedMonth = $article->published_month;
        if ($status === 'published') {
            $publishValidator = \Validator::make($request->all(), [
                'published_year' => 'required|integer|min:2000|max:2026',
                'published_month' => 'required|string|max:50',
            ]);
            if ($publishValidator->fails()) {
                return response()->json([
                    'message' => 'Publishing targeting metadata is required.',
                    'errors' => $publishValidator->errors()
                ], 422);
            }
            $publishedYear = $request->input('published_year');
            $publishedMonth = $request->input('published_month');
        }

        $rejectionReason = $article->rejection_reason;
        if (in_array($status, ['minor_review_rejected', 'fully_rejected'])) {
            $rejectionReasonValidator = \Validator::make($request->all(), [
                'rejection_reason' => 'required|string',
            ]);
            if ($rejectionReasonValidator->fails()) {
                return response()->json([
                    'message' => 'Rejection reason is required.',
                    'errors' => $rejectionReasonValidator->errors()
                ], 422);
            }
            $rejectionReason = $request->input('rejection_reason');
        } else {
            $rejectionReason = null;
        }

        $publishedAt = $article->published_at;
        if (($status === 'approved' || $status === 'published') && !$publishedAt) {
            $publishedAt = now();
        }

        $updateData = [
            'magazine_id' => $validated['magazine_id'],
            'title' => $validated['title'],
            'slug' => $slug,
            'abstract' => $validated['abstract'],
            'full_text' => $validated['full_text'],
            'pdf_path' => $pdfPath,
            'featured_image' => $featuredImagePath,
            'status' => $status,
            'published_at' => $publishedAt,
            'published_year' => $publishedYear,
            'published_month' => $publishedMonth,
            'rejection_reason' => $rejectionReason,
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
                        'university_name' => $coAuthor['university_name'] ?? null,
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
                    'university_name' => $coAuthor['university_name'] ?? null,
                ]);

                $coAuthorItem = [
                    'name' => $name,
                    'email' => $email,
                    'university_name' => $coAuthor['university_name'] ?? null,
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

        // If approved/published and pdf_path is empty, generate dynamic PDF
        if (in_array($article->status, ['approved', 'published']) && empty($article->pdf_path)) {
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

        // Send newsletter announcement when advanced to published
        if ($article->status === 'published' && $oldStatus !== 'published') {
            $this->sendArticleNewsletter($article);
        }

        // Dispatch queued Laravel Mailable to author for rejections
        if (in_array($article->status, ['minor_review_rejected', 'fully_rejected']) && $article->status !== $oldStatus) {
            \Illuminate\Support\Facades\Mail::to($article->user->email)->queue(
                new \App\Mail\ArticleRejectedMail($article, $article->status, $article->rejection_reason)
            );
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
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor') && !$user->hasRole('magazine_editor') && !$user->hasRole('magazine-editor'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // If user is a magazine_editor, optionally query only their assigned magazines (or general if they have global permission)
        $magazineIds = null;
        if (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor') && ($user->hasRole('magazine_editor') || $user->hasRole('magazine-editor'))) {
            $magazineIds = \DB::table('magazine_user')
                ->where('user_id', $user->id)
                ->pluck('magazine_id')
                ->toArray();
        }

        $query = Article::query();
        if ($magazineIds !== null) {
            $query->whereIn('magazine_id', $magazineIds);
        }

        $totalArticles = (clone $query)->count();
        $submittedArticles = (clone $query)->where('status', 'submitted')->count();
        $underReviewArticles = (clone $query)->where('status', 'under_review')->count();
        $approvedArticles = (clone $query)->where('status', 'approved')->count();
        $publishedArticles = (clone $query)->where('status', 'published')->count();
        $minorReviewRejectedArticles = (clone $query)->where('status', 'minor_review_rejected')->count();
        $fullyRejectedArticles = (clone $query)->where('status', 'fully_rejected')->count();
        $resubmittedArticles = (clone $query)->where('status', 'resubmitted')->count();

        $magazinesQuery = \App\Models\Magazine::query();
        if ($magazineIds !== null) {
            $magazinesQuery->whereIn('id', $magazineIds);
        }
        $totalMagazines = $magazinesQuery->count();
        $totalUsers = \App\Models\User::count(); // general users count remains system-wide

        $totalClicks = (clone $query)->sum('clicks');
        $totalImpressions = (clone $query)->sum('impressions');

        // Top articles by engagement
        $topArticles = Article::with(['magazine:id,title,slug,cover_image', 'user:id,name'])
            ->when($magazineIds !== null, function($q) use ($magazineIds) {
                $q->whereIn('magazine_id', $magazineIds);
            })
            ->orderByRaw('(clicks + impressions) DESC')
            ->limit(5)
            ->get();

        return response()->json([
            'articles_count' => [
                'total' => $totalArticles,
                'submitted' => $submittedArticles,
                'under_review' => $underReviewArticles,
                'approved' => $approvedArticles,
                'published' => $publishedArticles,
                'minor_review_rejected' => $minorReviewRejectedArticles,
                'fully_rejected' => $fullyRejectedArticles,
                'resubmitted' => $resubmittedArticles,
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
