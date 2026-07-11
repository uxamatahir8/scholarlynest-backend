<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Http\Requests\StoreArticleRequest;
use App\Http\Requests\UpdateArticleRequest;
use App\Http\Controllers\Admin\DeskObserverController;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Role;
use App\Models\User;
use App\Models\ArticleFile;
use App\Services\PdfGeneratorService;
use App\Services\NotificationService;
use App\Services\ArticleVersionService;
use App\Services\Media\CleanUploadResolver;
use App\Services\Media\MediaStorageService;
use App\Services\Security\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    protected PdfGeneratorService $pdfService;
    protected NotificationService $notificationService;
    protected ArticleVersionService $versionService;
    protected MediaStorageService $mediaStorage;

    public function __construct(PdfGeneratorService $pdfService, NotificationService $notificationService, ArticleVersionService $versionService, MediaStorageService $mediaStorage)
    {
        $this->pdfService = $pdfService;
        $this->notificationService = $notificationService;
        $this->versionService = $versionService;
        $this->mediaStorage = $mediaStorage;
    }

    public function show(string $idOrSlug): JsonResponse
    {
        $query = Article::with([
            'magazine:id,title,slug,cover_image',
            'user:id,name,created_at',
            'tags:id,name',
            'articleAuthors:id,article_id,co_author_name,affiliation,university_name,author_order,is_owner,is_corresponding',
            'assets:id,article_id,asset_type,original_filename,file_size,mime_type,title,caption,description,sort_order,scan_status',
            'publicationSections',
            'issue:id,volume_number,issue_number,special_title,issue_month,issue_year',
        ]);

        $article = is_numeric($idOrSlug)
            ? $query->find((int) $idOrSlug)
            : $query->where('slug', $idOrSlug)->first();

        if (!$article || ArticleStatus::normalize($article->status) !== ArticleStatus::PUBLISHED) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        $article->increment('impressions');

        $authorApprovedCount = Article::where('user_id', $article->user_id)
            ->where('status', 'published')
            ->count();

        $authorMetrics = [
            'total_papers_approved' => $authorApprovedCount,
            'member_since' => $article->user?->created_at?->format('M Y'),
        ];

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
            ->first(['id', 'slug', 'title']);

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
            ->first(['id', 'slug', 'title']);

        $articlePayload = array_merge($this->publicArticlePayload($article, true), [
            'previous_article_slug' => $previousArticle?->slug,
            'next_article_slug' => $nextArticle?->slug,
            'previous_article_title' => $previousArticle?->title,
            'next_article_title' => $nextArticle?->title,
        ]);

        return response()->json([
            'article' => $articlePayload,
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
        $limit = min($request->integer('limit', 10), 10);
        
        $articles = Article::where('status', 'published')
            ->with([
                'magazine:id,title,slug,cover_image',
                'user:id,name',
                'issue:id,volume_number,issue_number,special_title,issue_month,issue_year',
                'articleAuthors:id,article_id,co_author_name,author_order,is_owner,is_corresponding',
            ])
            ->orderByDesc('published_at')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Article $article) => $this->publicArticlePayload($article));

        return response()->json([
            'status' => 'success',
            'data' => $articles
        ]);
    }


    /**
     * GET /api/public/homepage-stats
     * Safe aggregate counts for public homepage discovery surfaces.
     */
    public function publicHomepageStats(): JsonResponse
    {
        $publishedArticleIds = Article::where('status', ArticleStatus::PUBLISHED)->pluck('id');

        $primaryAuthorCount = Article::where('status', ArticleStatus::PUBLISHED)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $coAuthorCount = \DB::table('article_author')
            ->whereIn('article_id', $publishedArticleIds)
            ->whereNotNull('co_author_name')
            ->where('co_author_name', '!=', '')
            ->distinct('co_author_name')
            ->count('co_author_name');

        return response()->json([
            'published_articles_count' => $publishedArticleIds->count(),
            'active_magazines_count' => Magazine::count(),
            'published_issues_count' => MagazineIssue::where(function ($query) {
                $query->where('status', 'published')->orWhere('is_published', true);
            })->count(),
            'public_contributors_count' => $primaryAuthorCount + $coAuthorCount,
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
    public function store(StoreArticleRequest $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($request->hasFile('featured_image') || $request->hasFile('pdf_file')) {
            return response()->json(['message' => 'Raw browser uploads are disabled for article files. Use the direct S3 upload-session flow.'], 410);
        }

        $validated = $request->validated();
        $authors = $request->academicAuthors();
        $authorResolution = $this->resolveArticleAuthors($authors, $user, $user->hasRole('super_admin'));
        $articleOwner = $authorResolution['owner'] ?? $user;
        $requestedStatus = ArticleStatus::normalize($validated['status'] ?? ArticleStatus::SUBMITTED) ?: ArticleStatus::SUBMITTED;

        $slug = !empty($validated['title'])
            ? Str::slug($validated['title']) . '-' . Str::lower(Str::random(6))
            : 'draft-' . Str::lower(Str::random(16));

        $articleData = array_merge($request->articlePayload(), [
            'user_id' => $articleOwner->id,
            'title' => $validated['title'] ?? null,
            'slug' => $slug,
            'full_text' => '',
            'pdf_path' => null,
            'featured_image' => null,
            'status' => $requestedStatus,
        ]);

        if ($requestedStatus === ArticleStatus::SUBMITTED) {
            $articleData['terms_accepted_at'] = now();
            $articleData['terms_accepted_by'] = $user->id;
            $articleData['terms_acceptance_ip'] = $request->ip();
        }

        if ($user->hasPermission('seo.articles')) {
            $articleData['seo_title'] = $validated['seo_title'] ?? null;
            $articleData['seo_description'] = $validated['seo_description'] ?? null;
            $articleData['seo_keywords'] = $validated['seo_keywords'] ?? null;
        }

        $article = \DB::transaction(function() use ($articleData, $request, $authorResolution, $user) {
            $article = Article::create($articleData);
            $this->syncTags($article, $request->input('tags'));
            $this->persistArticleAuthors($article, $authorResolution['authors']);
            $this->persistReviewerPreferences($article, $request->reviewerPreferencesPayload(), $user);

            return $article;
        });

        $linkedFileIds = [];
        if (!empty($validated['pdf_upload_id'])) {
            $upload = app(CleanUploadResolver::class)->resolveOwned($user, $validated['pdf_upload_id'], 'article_manuscript');
            $manuscriptFile = app(ArticleFileController::class)->createCleanDirectUploadFile($article, $upload, config('media_uploads.purposes.article_manuscript'));
            $article->update(['pdf_path' => $manuscriptFile->file_path]);
            $linkedFileIds[] = $manuscriptFile->id;
        }
        if ($requestedStatus === ArticleStatus::SUBMITTED) {
            $this->versionService->createSnapshot(
                $article->fresh(['articleAuthors', 'tags', 'files']),
                $user,
                'Initial Submission',
                'Initial manuscript submission.',
                null,
                $linkedFileIds
            );

            // Dispatch synchronized queued notifications
            event(new \App\Events\ArticleSubmitted($article, $this->notificationAuthors($authorResolution['authors'], $articleOwner->email)));
        }

        return response()->json([
            'message' => $requestedStatus === ArticleStatus::DRAFT
                ? 'Draft manuscript saved.'
                : 'Your research article has been submitted successfully for peer review.',
            'article' => $article->load(['tags', 'articleAuthors', 'reviewerPreferences'])
        ], $requestedStatus === ArticleStatus::DRAFT ? 201 : 211);
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

        $observedUser = DeskObserverController::resolveObservedUser($request, ['editor']);
        $scopeUser = $observedUser ?: $user;
        $query = $this->scopedAdminArticleQuery($user, $scopeUser, $observedUser)
            ->with(['magazine:id,title,slug,cover_image', 'user:id,name', 'tags:id,name', 'shareClicks', 'latestVersion'])
            ->withMax('versions as latest_submission_at', 'created_at');

        $this->applyAdminArticleFilters($query, $request);

        $perPage = $request->integer('per_page', 25);
        $articles = $query->orderByDesc('latest_submission_at')->orderByDesc('created_at')->paginate($perPage);

        $articles->getCollection()->transform(fn (Article $article) => $this->adminArticleSummaryPayload($article, $scopeUser));

        return response()->json($articles);
    }

    /**
     * GET /api/admin/articles/status-options
     * Status filter options available inside the caller's article scope.
     */
    public function adminStatusOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $observedUser = DeskObserverController::resolveObservedUser($request, ['editor']);
        $scopeUser = $observedUser ?: $user;
        $query = $this->scopedAdminArticleQuery($user, $scopeUser, $observedUser);
        $this->applyAdminArticleFilters($query, $request, false);

        $order = array_flip(ArticleStatus::ALL);
        $statuses = $query
            ->select('status', \DB::raw('count(*) as total'))
            ->whereNotNull('status')
            ->groupBy('status')
            ->get()
            ->sortBy(fn ($row) => $order[ArticleStatus::normalize($row->status) ?? $row->status] ?? PHP_INT_MAX)
            ->values()
            ->map(fn ($row) => [
                'value' => $row->status,
                'label' => ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::normalize($row->status) ?? $row->status]
                    ?? Str::headline(str_replace('_', ' ', (string) $row->status)),
                'count' => (int) $row->total,
            ]);

        return response()->json([
            'data' => $statuses,
        ]);
    }

    /**
     * PATCH /api/admin/articles/{id}/review
     * Admin operations endpoint to approve or reject articles.
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $article = Article::with('user')->find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        if (!$this->hasGlobalArticleAccess($user) && !$user->hasPermission('articles.auto-approve')) {
            if (!$this->isAssignedToArticleMagazine($user, $article, ['editor'])) {
                return response()->json(['message' => 'Forbidden. You are not assigned to this magazine.'], 403);
            }
            if (ArticleStatus::normalize($request->input('status')) === ArticleStatus::PUBLISHED) {
                return response()->json(['message' => 'Forbidden. Editors cannot publish articles directly.'], 403);
            }
        }

        $validated = $request->validate([
            'status' => 'required|' . ArticleStatus::validationRuleWithLegacy([
                'approved',
                'published',
                'minor_review_rejected',
                'fully_rejected',
                ArticleStatus::ACCEPTED,
                ArticleStatus::REJECTED,
                ArticleStatus::REVISION_REQUIRED,
                ArticleStatus::MINOR_REVISION_REQUIRED,
                ArticleStatus::MAJOR_REVISION_REQUIRED,
            ]),
            'rejection_reason' => 'nullable|string',
            'published_year' => 'required_if:status,published|nullable|integer|min:2000|max:2026',
            'published_month' => 'required_if:status,published|nullable|string|max:50',
        ]);

        $oldStatus = $article->status;
        $normalizedStatus = ArticleStatus::normalize($validated['status']);
        if (!ArticleStatus::canTransition($oldStatus, $normalizedStatus)) {
            return response()->json([
                'message' => "Invalid status transition from {$oldStatus} to {$normalizedStatus}.",
                'errors' => ['status' => ["Invalid status transition from {$oldStatus} to {$normalizedStatus}."]]
            ], 422);
        }

        $article->status = $normalizedStatus;
        
        if (ArticleStatus::isRevisionRequired($normalizedStatus) || ArticleStatus::isRejected($normalizedStatus)) {
            if (empty($validated['rejection_reason'])) {
                return response()->json([
                    'message' => 'Rejection or revision reason is required.',
                    'errors' => ['rejection_reason' => ['Rejection or revision reason is required.']]
                ], 422);
            }
            $article->rejection_reason = $validated['rejection_reason'];
            $article->published_year = null;
            $article->published_month = null;
        } else {
            $article->rejection_reason = null;
            if ($normalizedStatus === ArticleStatus::PUBLISHED) {
                $article->published_year = $validated['published_year'];
                $article->published_month = $validated['published_month'];
            }
            if (!$article->published_at) {
                $article->published_at = now();
            }
        }

        // If approved/published and pdf_path is empty, compile and generate a clean dynamic PDF download
        if (ArticleStatus::isAcceptedOrPublished($normalizedStatus) && empty($article->pdf_path)) {
            try {
                $generatedPdfUrl = $this->pdfService->generate($article);
                $article->pdf_path = $generatedPdfUrl;
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Article status updated, but PDF generation failed. Please try again.',
                ], 500);
            }
        }

        $article->save();

        if (ArticleStatus::normalize($article->status) !== ArticleStatus::normalize($oldStatus)) {
            $this->dispatchStatusWorkflowEvent($article->fresh(), $user, $oldStatus, $article->status);
            if (ArticleStatus::normalize($article->status) === ArticleStatus::ACCEPTED) {
                $this->versionService->createSnapshot(
                    $article->fresh(['articleAuthors', 'tags', 'files']),
                    $user,
                    'Accepted Manuscript',
                    $article->rejection_reason
                );
            }
        }

        // Send newsletter announcement when advanced to published
        if (ArticleStatus::normalize($article->status) === ArticleStatus::PUBLISHED && ArticleStatus::normalize($oldStatus) !== ArticleStatus::PUBLISHED) {
            $this->sendArticleNewsletter($article);
        }

        return response()->json([
            'message' => 'Article status updated successfully.',
            'article' => $this->adminArticleSummaryPayload($article->fresh(['magazine:id,title,slug,cover_image', 'user:id,name', 'tags:id,name', 'shareClicks']), $user)
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

        $article = Article::with([
            'tags:id,name',
            'magazine:id,title,slug,cover_image',
            'issue',
            'shareClicks',
            'articleAuthors',
            'reviewerPreferences',
            'assets:id,article_id,original_filename,file_size,mime_type',
            'subEditorAssignments.subEditor:id,name,email',
            'reviewerAssignments.reviewer:id,name,email',
            'editorialDecisions.decider:id,name,email',
            'productionAssignments.user:id,name,email',
        ])->find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // Authorize via ArticlePolicy
        if ($user->cannot('view', $article)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($request->query('view_context') === 'edit') {
            if (
                $request->boolean('observer_readonly')
                || $request->filled('observer_user')
                || $request->filled('observer_user_id')
            ) {
                return response()->json(['message' => 'Observer mode is read-only.'], 403);
            }

            if (!ArticleStatus::isEditableStatus($article->status)) {
                return response()->json([
                    'message' => 'This manuscript cannot be edited at its current workflow stage.',
                ], 422);
            }

            if ($user->cannot('update', $article)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        // first-read trigger: transition 'submitted' to 'under_review' on admin/editor view
        if (ArticleStatus::normalize($article->status) === ArticleStatus::SUBMITTED) {
            $isAdminOrEditor = $this->hasGlobalArticleAccess($user)
                || $this->isAssignedToArticleMagazine($user, $article, ['editor']);
            if ($isAdminOrEditor) {
                $oldStatus = $article->status;
                $article->status = ArticleStatus::UNDER_REVIEW;
                $article->save();
                $this->dispatchStatusWorkflowEvent($article->fresh(), $user, $oldStatus, ArticleStatus::UNDER_REVIEW);
            }
        }

        return response()->json($this->adminArticleDetailPayload($article->fresh([
            'tags:id,name',
            'magazine:id,title,slug,cover_image',
            'issue',
            'shareClicks',
            'articleAuthors',
            'reviewerPreferences',
            'assets:id,article_id,original_filename,file_size,mime_type',
            'subEditorAssignments.subEditor:id,name,email',
            'reviewerAssignments.reviewer:id,name,email',
            'editorialDecisions.decider:id,name,email',
            'productionAssignments.user:id,name,email',
        ]), $user));
    }

    /**
     * PUT /api/admin/articles/{id}
     * Update article metadata and content.
     */
    public function update(UpdateArticleRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $article = Article::find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        if ($user->cannot('view', $article)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($request->hasFile('featured_image') || $request->hasFile('pdf_file')) {
            return response()->json(['message' => 'Raw browser uploads are disabled for article files. Use the direct S3 upload-session flow.'], 410);
        }

        if (!ArticleStatus::isEditableStatus($article->status)) {
            return response()->json([
                'message' => 'This manuscript cannot be edited at its current workflow stage.',
            ], 422);
        }

        // Check if user has editorial privileges
        $isEditorial = $this->hasGlobalArticleAccess($user)
            || $this->isAssignedToArticleMagazine($user, $article, ['editor']);

        // Authorize via ArticlePolicy
        if ($user->cannot('update', $article)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validated();
        $authors = $request->academicAuthors();
        $authorResolution = $this->resolveArticleAuthors($authors, $user, $user->hasRole('super_admin'));
        $articleOwner = $authorResolution['owner'] ?? $article->user ?? $user;

        $pdfPath = $article->pdf_path;

        $slug = $article->slug;
        if ($validated['title'] !== $article->title) {
            $slug = Str::slug($validated['title']);
        }

        // Restrict status edits to admins/editors only or handle resubmission
        $oldStatus = $article->status;
        $status = $article->status;

        $isEditorial = $this->hasGlobalArticleAccess($user)
            || $this->isAssignedToArticleMagazine($user, $article, ['editor']);

        if (!$isEditorial) {
            // Authors saving requested revisions resubmit the manuscript for editorial review.
            $requestedStatus = ArticleStatus::normalize($validated['status'] ?? $article->status);
            $normalizedOldStatus = ArticleStatus::normalize($article->status);
            $status = $normalizedOldStatus === ArticleStatus::DRAFT
                ? ($requestedStatus === ArticleStatus::SUBMITTED ? ArticleStatus::SUBMITTED : ArticleStatus::DRAFT)
                : (ArticleStatus::isRevisionRequired($normalizedOldStatus) ? ArticleStatus::RESUBMITTED : $normalizedOldStatus);
        } else {
            $status = ArticleStatus::normalize($validated['status'] ?? $article->status);
            if (!ArticleStatus::canTransition($oldStatus, $status)) {
                return response()->json([
                    'message' => "Invalid status transition from {$oldStatus} to {$status}.",
                    'errors' => ['status' => ["Invalid status transition from {$oldStatus} to {$status}."]]
                ], 422);
            }
        }

        $publishedYear = $article->published_year;
        $publishedMonth = $article->published_month;
        if ($status === ArticleStatus::PUBLISHED) {
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
        if (ArticleStatus::isRevisionRequired($status) || ArticleStatus::isRejected($status)) {
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
        if (ArticleStatus::isAcceptedOrPublished($status) && !$publishedAt) {
            $publishedAt = now();
        }

        $updateData = array_merge($request->articlePayload(), [
            'user_id' => $articleOwner->id,
            'title' => $validated['title'],
            'slug' => $slug,
            'pdf_path' => $pdfPath,
            'status' => $status,
            'published_at' => $publishedAt,
            'published_year' => $publishedYear,
            'published_month' => $publishedMonth,
            'rejection_reason' => $rejectionReason,
        ]);

        if (ArticleStatus::normalize($status) === ArticleStatus::SUBMITTED
            && ArticleStatus::normalize($oldStatus) === ArticleStatus::DRAFT) {
            $updateData['terms_accepted_at'] = now();
            $updateData['terms_accepted_by'] = $user->id;
            $updateData['terms_acceptance_ip'] = $request->ip();
        }

        if ($user->hasPermission('seo.articles')) {
            $updateData['seo_title'] = $validated['seo_title'] ?? null;
            $updateData['seo_description'] = $validated['seo_description'] ?? null;
            $updateData['seo_keywords'] = $validated['seo_keywords'] ?? null;
        }

        $linkedFileIds = [];
        $additionalFileIds = collect($validated['additional_file_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        if ($additionalFileIds->isNotEmpty()) {
            $ownedAdditionalFiles = ArticleFile::query()
                ->where('article_id', $article->id)
                ->where('uploaded_by', $user->id)
                ->where('scan_status', 'clean')
                ->whereIn('id', $additionalFileIds)
                ->pluck('id');
            if ($ownedAdditionalFiles->count() !== $additionalFileIds->count()) {
                return response()->json([
                    'message' => 'One or more supporting files failed validation. The manuscript was not submitted.',
                    'errors' => ['additional_file_ids' => ['Every supporting file must be clean and owned by the submitting author.']],
                ], 422);
            }
            $linkedFileIds = $ownedAdditionalFiles->all();
        }
        $manuscriptUpload = !empty($validated['pdf_upload_id'])
            ? app(CleanUploadResolver::class)->resolveOwned($user, $validated['pdf_upload_id'], ['article_manuscript', 'article_revision'])
            : null;
        $responseUpload = !empty($validated['revision_response_upload_id'])
            ? app(CleanUploadResolver::class)->resolveOwned($user, $validated['revision_response_upload_id'], 'article_revision_response')
            : null;

        \DB::transaction(function() use ($article, $updateData, $request, $authorResolution, $user) {
            $article->update($updateData);
            $this->syncTags($article, $request->input('tags'));
            $this->persistArticleAuthors($article, $authorResolution['authors']);
            $this->persistReviewerPreferences($article, $request->reviewerPreferencesPayload(), $user);
        });

        if ($manuscriptUpload) {
            $purposeConfig = config('media_uploads.purposes.' . $manuscriptUpload->purpose);
            $manuscriptFile = app(ArticleFileController::class)->createCleanDirectUploadFile($article->fresh(), $manuscriptUpload, $purposeConfig);
            $article->update(['pdf_path' => $manuscriptFile->file_path]);
            $pdfPath = $manuscriptFile->file_path;
            $linkedFileIds[] = $manuscriptFile->id;
        }
        if ($responseUpload) {
            $purposeConfig = config('media_uploads.purposes.' . $responseUpload->purpose);
            $responseFile = app(ArticleFileController::class)->createCleanDirectUploadFile($article->fresh(), $responseUpload, $purposeConfig);
            $linkedFileIds[] = $responseFile->id;
        }

        if (ArticleStatus::normalize($status) !== ArticleStatus::normalize($oldStatus)) {
            $this->dispatchStatusWorkflowEvent($article->fresh(), $user, $oldStatus, $status);
        }

        if (ArticleStatus::normalize($status) === ArticleStatus::RESUBMITTED && ArticleStatus::isRevisionRequired($oldStatus)) {
            $this->versionService->createSnapshot(
                $article->fresh(['articleAuthors', 'tags', 'files']),
                $user,
                'Revised Manuscript',
                $request->input('change_summary'),
                null,
                $linkedFileIds
            );
        } elseif (ArticleStatus::normalize($status) === ArticleStatus::ACCEPTED && ArticleStatus::normalize($oldStatus) !== ArticleStatus::ACCEPTED) {
            $this->versionService->createSnapshot(
                $article->fresh(['articleAuthors', 'tags', 'files']),
                $user,
                'Accepted Manuscript',
                $request->input('change_summary')
            );
        } elseif (ArticleStatus::normalize($status) === ArticleStatus::SUBMITTED && ArticleStatus::normalize($oldStatus) === ArticleStatus::DRAFT) {
            $this->versionService->createSnapshot(
                $article->fresh(['articleAuthors', 'tags', 'files']),
                $user,
                'Initial Submission',
                $request->input('change_summary') ?: 'Draft submitted for review.',
                null,
                $linkedFileIds
            );
        }

        // If approved/published and pdf_path is empty, generate dynamic PDF
        if (ArticleStatus::isAcceptedOrPublished($article->status) && empty($article->pdf_path)) {
            try {
                $generatedPdfUrl = $this->pdfService->generate($article);
                $article->pdf_path = $generatedPdfUrl;
                $article->save();
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Article updated successfully, but PDF generation failed. Please try again.'
                ], 500);
            }
        }

        // Send newsletter announcement when advanced to published
        if (ArticleStatus::normalize($article->status) === ArticleStatus::PUBLISHED && ArticleStatus::normalize($oldStatus) !== ArticleStatus::PUBLISHED) {
            $this->sendArticleNewsletter($article);
        }

        return response()->json([
            'message' => 'Article updated successfully.',
            'article' => $this->adminArticleDetailPayload($article->fresh(['tags:id,name', 'magazine:id,title,slug,cover_image', 'issue', 'articleAuthors', 'reviewerPreferences', 'assets:id,article_id,original_filename,file_size,mime_type']), $user)
        ]);
    }

    /**
     * Sync tags onto the article, auto-creating string tags in the process.
     */
    protected function resolveArticleAuthors(array $authors, User $fallbackOwner, bool $createMissingAuthors): array
    {
        $authorRole = Role::where('name', 'author')->first();
        $resolved = [];
        $owner = null;

        foreach ($authors as $author) {
            $email = strtolower(trim($author['email']));
            $linkedUser = User::whereRaw('LOWER(email) = ?', [$email])->first();
            $shouldCreateAccount = !$linkedUser && ($createMissingAuthors || $author['create_account'] || $author['can_edit'] || $author['is_owner']);

            if ($shouldCreateAccount) {
                $linkedUser = User::create([
                    'name' => $author['name'],
                    'email' => $email,
                    'password' => null,
                    'needs_password_reset' => true,
                    'email_verified_at' => now(),
                    'role_id' => $authorRole?->id,
                    'university_name' => $author['affiliation'] ?: null,
                ]);
            }

            if (!$linkedUser && $author['is_owner']) {
                $linkedUser = $fallbackOwner;
            }

            if ($author['is_owner']) {
                $owner = $linkedUser ?: $fallbackOwner;
            }

            $resolved[] = array_merge($author, [
                'user_id' => $linkedUser?->id,
                'account_provisioned' => (bool) ($shouldCreateAccount && $linkedUser),
            ]);
        }

        return [
            'owner' => $owner ?: $fallbackOwner,
            'authors' => $resolved,
        ];
    }

    protected function persistArticleAuthors(Article $article, array $authors): void
    {
        $article->articleAuthors()->delete();

        foreach ($authors as $author) {
            \App\Models\ArticleAuthor::create([
                'article_id' => $article->id,
                'user_id' => $author['user_id'] ?? null,
                'co_author_name' => $author['name'],
                'co_author_email' => $author['email'],
                'affiliation' => $author['affiliation'] ?: null,
                'department' => $author['department'] ?: null,
                'country' => $author['country'] ?: null,
                'orcid' => $author['orcid'] ?: null,
                'author_order' => $author['author_order'],
                'is_owner' => $author['is_owner'],
                'is_corresponding' => $author['is_corresponding'],
                'contribution_statement' => $author['contribution_statement'] ?: null,
                'can_edit' => $author['can_edit'],
                'account_provisioned' => (bool) ($author['account_provisioned'] ?? false),
                'university_name' => $author['affiliation'] ?: null,
            ]);
        }
    }

    protected function persistReviewerPreferences(Article $article, array $preferences, User $creator): void
    {
        $article->reviewerPreferences()->delete();

        foreach (['suggested', 'opposed'] as $type) {
            foreach (($preferences[$type] ?? []) as $preference) {
                \App\Models\ArticleReviewerPreference::create([
                    'article_id' => $article->id,
                    'created_by_author_id' => $creator->id,
                    'type' => $type,
                    'name' => $preference['name'],
                    'email' => $preference['email'],
                    'affiliation' => $preference['affiliation'] ?: null,
                    'designation' => $preference['designation'] ?: null,
                    'reason' => null,
                ]);
            }
        }
    }

    protected function notificationAuthors(array $authors, string $ownerEmail): array
    {
        $ownerEmail = strtolower($ownerEmail);

        return collect($authors)
            ->reject(fn ($author) => strtolower($author['email']) === $ownerEmail)
            ->map(fn ($author) => [
                'name' => $author['name'],
                'email' => $author['email'],
                'university_name' => $author['affiliation'] ?: null,
                'can_edit' => $author['can_edit'],
                'create_account' => $author['create_account'],
                'user_id' => $author['user_id'] ?? null,
                'account_provisioned' => (bool) ($author['account_provisioned'] ?? false),
            ])
            ->values()
            ->all();
    }

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

    private function dispatchStatusWorkflowEvent(Article $article, User $actor, string $oldStatus, string $newStatus): void
    {
        $normalizedStatus = ArticleStatus::normalize($newStatus);

        $event = match ($normalizedStatus) {
            ArticleStatus::UNDER_REVIEW => 'article.under_review',
            ArticleStatus::REVIEWER_ASSIGNED => 'reviewer.assigned',
            ArticleStatus::RESUBMITTED => 'article.resubmitted',
            ArticleStatus::ACCEPTED => 'article.accepted',
            ArticleStatus::REJECTED => 'article.rejected',
            ArticleStatus::REVISION_REQUIRED, ArticleStatus::MINOR_REVISION_REQUIRED, ArticleStatus::MAJOR_REVISION_REQUIRED => 'revision.requested',
            ArticleStatus::COPY_EDITING, ArticleStatus::PROOFREADING => 'production.assigned',
            ArticleStatus::READY_FOR_PUBLICATION => 'article.ready_for_publication',
            ArticleStatus::PUBLISHED => 'article.published',
            default => null,
        };

        if (!$event) {
            return;
        }

        event(new ArticleWorkflowEventOccurred($article, $event, $actor, [
            'from_status' => ArticleStatus::normalize($oldStatus),
            'to_status' => $normalizedStatus,
        ]));
    }

    /**
     * GET /api/admin/stats
     * Aggregated statistics for the admin dashboard panel.
     */
    public function adminStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $observedUser = DeskObserverController::resolveObservedUser($request, ['editor']);
        $scopeUser = $observedUser ?: $user;

        if (!$scopeUser || (!$this->hasGlobalArticleAccess($scopeUser) && !$this->usesMagazineArticleScope($scopeUser))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $magazineIds = null;
        $publisherScoped = false;
        if (!$this->hasGlobalArticleAccess($scopeUser) || $observedUser) {
            $publisherScoped = $this->usesPublisherArticleScope($scopeUser);
            $magazineIds = $publisherScoped
                ? $this->assignedMagazineIds($scopeUser, ['publisher'])
                : $this->assignedMagazineIds($scopeUser, ['editor']);
        }

        $query = Article::query();
        if ($magazineIds !== null) {
            $query->whereIn('magazine_id', $magazineIds);
        }
        if ($publisherScoped) {
            $query->whereIn('status', $this->publisherVisibleStatuses());
        }

        $totalArticles = (clone $query)->count();
        $submittedArticles = (clone $query)->whereIn('status', ArticleStatus::queryValues(ArticleStatus::SUBMITTED))->count();
        $underReviewArticles = (clone $query)->whereIn('status', ArticleStatus::queryValues(ArticleStatus::UNDER_REVIEW))->count();
        $approvedArticles = (clone $query)->whereIn('status', ArticleStatus::queryValues(ArticleStatus::ACCEPTED))->count();
        $publishedArticles = (clone $query)->whereIn('status', ArticleStatus::queryValues(ArticleStatus::PUBLISHED))->count();
        $minorReviewRejectedArticles = (clone $query)->whereIn('status', array_values(array_unique(array_merge(
            ArticleStatus::queryValues(ArticleStatus::REVISION_REQUIRED),
            ArticleStatus::queryValues(ArticleStatus::MINOR_REVISION_REQUIRED),
            ArticleStatus::queryValues(ArticleStatus::MAJOR_REVISION_REQUIRED)
        ))))->count();
        $fullyRejectedArticles = (clone $query)->whereIn('status', ArticleStatus::queryValues(ArticleStatus::REJECTED))->count();
        $resubmittedArticles = (clone $query)->whereIn('status', ArticleStatus::queryValues(ArticleStatus::RESUBMITTED))->count();

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
            ->when($publisherScoped, function ($q) {
                $q->whereIn('status', $this->publisherVisibleStatuses());
            })
            ->orderByRaw('(clicks + impressions) DESC')
            ->limit(5)
            ->get();

        return response()->json([
            'articles_count' => [
                'total' => $totalArticles,
                'submitted' => $submittedArticles,
                'pending' => $submittedArticles,
                'under_review' => $underReviewArticles,
                'approved' => $approvedArticles,
                'accepted' => $approvedArticles,
                'published' => $publishedArticles,
                'minor_review_rejected' => $minorReviewRejectedArticles,
                'revision_required' => $minorReviewRejectedArticles,
                'fully_rejected' => $fullyRejectedArticles,
                'rejected' => $fullyRejectedArticles,
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
        $article = Article::find($id);

        if (!$article || ArticleStatus::normalize($article->status) !== ArticleStatus::PUBLISHED || empty($article->pdf_path)) {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        return $this->mediaStorage->downloadResponse($article->pdf_path, basename($article->pdf_path), 'application/pdf', 'inline');
    }

    private function publicArticlePayload(Article $article, bool $includeBody = false): array
    {
        $article->loadMissing(['assets', 'publicationSections', 'tags']);

        $payload = [
            'id' => $article->id,
            'title' => $article->title,
            'subtitle' => $article->subtitle,
            'slug' => $article->slug,
            'abstract' => app(HtmlSanitizer::class)->sanitize($article->abstract),
            'abstract_text' => trim(html_entity_decode(strip_tags(app(HtmlSanitizer::class)->sanitize($article->abstract)))),
            'featured_image' => $article->featured_image_url,
            'featured_image_url' => $article->featured_image_url,
            'doi' => $article->doi,
            'open_access_label' => $article->open_access_label,
            'is_peer_reviewed' => $article->is_peer_reviewed,
            'academic_editor' => $article->academic_editor,
            'received_at' => $article->received_at,
            'accepted_at' => $article->accepted_at,
            'license_statement' => $article->license_statement,
            'data_availability_statement' => $article->data_availability_statement,
            'funding_statement' => $article->funding_statement,
            'competing_interests_statement' => $article->competing_interests_statement,
            'abbreviations' => $article->abbreviations,
            'citation_text' => $article->citation_text,
            'published_at' => $article->published_at,
            'created_at' => $article->created_at,
            'published_year' => $article->published_year,
            'published_month' => $article->published_month,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'article_type' => $article->article_type,
            'article_category' => $article->article_category,
            'seo_title' => $article->seo_title ?: $article->title . ' | ' . ($article->magazine?->title ?? 'ScholarlyNest'),
            'seo_description' => $article->seo_description ?: Str::limit(strip_tags((string) $article->abstract), 160, ''),
            'seo_keywords' => $article->seo_keywords ?: $article->tags->pluck('name')->implode(', '),
            'og_image' => $article->magazine?->cover_image_url,
            'has_pdf' => !empty($article->pdf_path),
            'pdf_url' => !empty($article->pdf_path) ? url("/api/articles/{$article->id}/download-pdf") : null,
            'magazine' => $article->magazine ? [
                'id' => $article->magazine->id,
                'title' => $article->magazine->title,
                'slug' => $article->magazine->slug,
                'cover_image' => $article->magazine->cover_image_url,
                'cover_image_url' => $article->magazine->cover_image_url,
            ] : null,
            'user' => $article->user ? [
                'id' => $article->user->id,
                'name' => $article->user->name,
            ] : null,
            'issue' => $article->issue ? [
                'id' => $article->issue->id,
                'volume_number' => $article->issue->volume_number,
                'issue_number' => $article->issue->issue_number,
                'special_title' => $article->issue->special_title,
                'issue_month' => $article->issue->issue_month,
                'issue_year' => $article->issue->issue_year,
            ] : null,
            'article_authors' => $article->articleAuthors
                ->sortBy('author_order')
                ->map(fn ($author) => [
                    'id' => $author->id,
                    'co_author_name' => $author->co_author_name,
                    'affiliation' => $author->affiliation,
                    'university_name' => $author->university_name,
                    'department' => $author->department,
                    'country' => $author->country,
                    'orcid' => $author->orcid,
                    'author_order' => $author->author_order,
                    'is_owner' => $author->is_owner,
                    'is_corresponding' => $author->is_corresponding,
                ])
                ->values(),
            'assets' => $article->assets
                ->filter(fn ($asset) => ($asset->scan_status ?? 'clean') === 'clean' && ($asset->asset_type ?? 'supplementary') !== 'image')
                ->map(fn ($asset) => [
                    'id' => $asset->id,
                    'asset_type' => $asset->asset_type ?: 'supplementary',
                    'original_filename' => $asset->original_filename,
                    'file_size' => $asset->file_size,
                    'mime_type' => $asset->mime_type,
                    'scan_status' => $asset->scan_status ?? 'clean',
                    'available' => ($asset->scan_status ?? 'clean') === 'clean',
                ])
                ->values(),
            'article_images' => $article->assets
                ->filter(fn ($asset) => ($asset->scan_status ?? 'clean') === 'clean' && ($asset->asset_type ?? null) === 'image')
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($asset, $index) => [
                    'id' => $asset->id,
                    'label' => 'Figure ' . ($index + 1),
                    'title' => $asset->title,
                    'caption' => $asset->caption,
                    'description' => $asset->description,
                    'original_filename' => $asset->original_filename,
                    'file_size' => $asset->file_size,
                    'mime_type' => $asset->mime_type,
                    'download_url' => url("/api/articles/assets/{$asset->id}/download"),
                ])
                ->values(),
            'publication_sections' => $article->publicationSections
                ->map(fn ($section) => [
                    'id' => $section->id,
                    'section_key' => $section->section_key,
                    'title' => $section->title,
                    'content_html' => app(HtmlSanitizer::class)->sanitize($section->content_html),
                    'content_text' => $section->content_text,
                    'sort_order' => $section->sort_order,
                    'has_image' => !empty($section->media_upload_session_id),
                    'image_url' => $section->media_upload_session_id ? url("/api/articles/publication-sections/{$section->id}/image") : null,
                ])
                ->sortBy('sort_order')
                ->values(),
        ];

        if ($includeBody) {
            $payload['full_text'] = $article->full_text;
        }

        return $payload;
    }

    private function adminArticleSummaryPayload(Article $article, User $viewer): array
    {
        $article->loadMissing(['magazine:id,title,slug,cover_image', 'user:id,name', 'tags:id,name', 'latestVersion']);

        return [
            'id' => $article->id,
            'tracking_code' => $article->tracking_code,
            'latest_tracking_code' => $article->latestVersion?->revision_tracking_code ?: $article->tracking_code,
            'latest_revision_number' => $article->latestVersion?->revision_number,
            'latest_submission_at' => $article->latestVersion?->created_at ?: $article->created_at,
            'magazine_id' => $article->magazine_id,
            'title' => $article->title,
            'subtitle' => $article->subtitle,
            'slug' => $article->slug,
            'abstract' => $article->abstract,
            'status' => $article->status,
            'author_status' => ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::normalize($article->status)] ?? $article->status,
            'can_edit_article' => $viewer->can('update', $article),
            'featured_image' => $article->featured_image,
            'featured_image_url' => $article->featured_image_url,
            'doi' => $article->doi,
            'published_at' => $article->published_at,
            'published_year' => $article->published_year,
            'published_month' => $article->published_month,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'has_pdf' => !empty($article->pdf_path),
            'magazine' => $article->magazine ? [
                'id' => $article->magazine->id,
                'title' => $article->magazine->title,
                'slug' => $article->magazine->slug,
                'cover_image' => $article->magazine->cover_image,
                'cover_image_url' => $article->magazine->cover_image_url,
            ] : null,
            'user' => $article->user ? [
                'id' => $article->user->id,
                'name' => $article->user->name,
            ] : null,
            'tags' => $article->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values(),
            'clicks' => $article->clicks,
            'impressions' => $article->impressions,
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
        ];
    }

    private function adminArticleDetailPayload(Article $article, User $viewer): array
    {
        $article->loadMissing(['articleAuthors', 'assets', 'issue']);
        $payload = $this->adminArticleSummaryPayload($article, $viewer);
        $canViewEditorial = $this->hasGlobalArticleAccess($viewer)
            || $this->isAssignedToArticleMagazine($viewer, $article, ['editor']);
        $isAuthor = (int) $article->user_id === (int) $viewer->id
            || $article->articleAuthors->contains(fn ($author) => (int) $author->user_id === (int) $viewer->id || strtolower((string) $author->co_author_email) === strtolower((string) $viewer->email));

        $payload += [
            'full_text' => $article->full_text,
            'keywords' => $article->keywords,
            'article_category' => $article->article_category,
            'article_type' => $article->article_type,
            'subject_area' => $article->subject_area,
            'language' => $article->language,
            'ethical_approval_statement' => $article->ethical_approval_statement,
            'conflict_of_interest_statement' => $article->conflict_of_interest_statement,
            'funding_statement' => $article->funding_statement,
            'data_availability_statement' => $article->data_availability_statement,
            'author_contribution_statement' => $article->author_contribution_statement,
            'change_summary' => $article->change_summary,
            'revision_response' => $article->revision_response,
            'rejection_reason' => $article->rejection_reason,
            'issue' => $article->issue ? [
                'id' => $article->issue->id,
                'volume_number' => $article->issue->volume_number,
                'issue_number' => $article->issue->issue_number,
                'special_title' => $article->issue->special_title,
                'issue_month' => $article->issue->issue_month,
                'issue_year' => $article->issue->issue_year,
            ] : null,
            'article_authors' => $article->articleAuthors
                ->sortBy('author_order')
                ->map(function ($author) use ($isAuthor, $canViewEditorial) {
                    $data = [
                        'id' => $author->id,
                        'user_id' => $author->user_id,
                        'co_author_name' => $author->co_author_name,
                        'affiliation' => $author->affiliation,
                        'university_name' => $author->university_name,
                        'department' => $author->department,
                        'country' => $author->country,
                        'orcid' => $author->orcid,
                        'author_order' => $author->author_order,
                        'is_owner' => $author->is_owner,
                        'is_corresponding' => $author->is_corresponding,
                    ];
                    if ($isAuthor || $canViewEditorial) {
                        $data['co_author_email'] = $author->co_author_email;
                        $data['can_edit'] = $author->can_edit;
                    }
                    return $data;
                })
                ->values(),
            'reviewer_preferences' => $this->reviewerPreferencePayload($article, $viewer),
            'assets' => $article->assets
                ->map(fn ($asset) => [
                    'id' => $asset->id,
                    'original_filename' => $asset->original_filename,
                    'file_size' => $asset->file_size,
                    'mime_type' => $asset->mime_type,
                    'download_url' => url("/api/articles/assets/{$asset->id}/download"),
                ])
                ->values(),
            'resume_step' => $this->draftResumeStep($article),
            'next_step' => $this->draftResumeStep($article),
            'completion_step' => $this->draftResumeStep($article),
        ];

        if ($canViewEditorial) {
            $payload['seo_title'] = $article->seo_title;
            $payload['seo_description'] = $article->seo_description;
            $payload['seo_keywords'] = $article->seo_keywords;
            $payload['sub_editor_assignments'] = $article->subEditorAssignments?->map(fn ($assignment) => $this->assignmentPreviewPayload($assignment, 'subEditor'))->values() ?? [];
            $payload['reviewer_assignments'] = $article->reviewerAssignments?->map(fn ($assignment) => $this->assignmentPreviewPayload($assignment, 'reviewer', true))->values() ?? [];
            $payload['editorial_decisions'] = $article->editorialDecisions?->map(fn ($decision) => [
                'id' => $decision->id,
                'decision' => $decision->decision,
                'decision_source' => $decision->decision_source,
                'comments_for_author' => $decision->comments_for_author,
                'internal_notes' => $decision->internal_notes,
                'decision_date' => $decision->decision_date,
                'decider' => $decision->decider ? ['id' => $decision->decider->id, 'name' => $decision->decider->name] : null,
            ])->values() ?? [];
        }

        return $payload;
    }

    private function draftResumeStep(Article $article): int
    {
        $article->loadMissing(['articleAuthors', 'reviewerPreferences', 'assets']);

        $hasBasics = (bool) (
            $article->magazine_id
            || trim((string) $article->title) !== ''
            || trim(strip_tags((string) $article->abstract)) !== ''
        );
        if (!$hasBasics) {
            return 1;
        }

        $hasCollaborators = $article->articleAuthors
            ->filter(fn ($author) => !$author->is_owner || (int) $author->author_order > 1)
            ->isNotEmpty();
        if (!$hasCollaborators) {
            return 2;
        }

        if ($article->reviewerPreferences->isEmpty()) {
            return 3;
        }

        $hasClassificationOrDeclaration = (bool) (
            !empty($article->keywords)
            || trim((string) $article->article_category) !== ''
            || trim((string) $article->article_type) !== ''
            || trim((string) $article->subject_area) !== ''
            || trim((string) $article->language) !== ''
            || trim((string) $article->ethical_approval_statement) !== ''
            || trim((string) $article->conflict_of_interest_statement) !== ''
            || trim((string) $article->funding_statement) !== ''
            || trim((string) $article->data_availability_statement) !== ''
            || trim((string) $article->author_contribution_statement) !== ''
        );

        $hasUploads = !empty($article->pdf_path) || $article->assets->isNotEmpty();
        if (!$hasClassificationOrDeclaration && !$hasUploads) {
            return 4;
        }

        return 5;
    }

    private function reviewerPreferencePayload(Article $article, User $viewer): array
    {
        $canViewEditorial = $this->hasGlobalArticleAccess($viewer)
            || $this->isAssignedToArticleMagazine($viewer, $article, ['editor']);
        $isAuthor = (int) $article->user_id === (int) $viewer->id
            || $article->articleAuthors->contains(fn ($author) => (int) $author->user_id === (int) $viewer->id || strtolower((string) $author->co_author_email) === strtolower((string) $viewer->email));

        if (!$canViewEditorial && !$isAuthor) {
            return ['suggested' => [], 'opposed' => []];
        }

        return $article->reviewerPreferences
            ->groupBy('type')
            ->map(fn ($items) => $items->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'name' => $item->name,
                'email' => $item->email,
                'affiliation' => $item->affiliation,
                'designation' => $item->designation,
            ])->values())
            ->union(['suggested' => collect(), 'opposed' => collect()])
            ->map(fn ($items) => $items->values())
            ->all();
    }

    private function assignmentPreviewPayload($assignment, string $relation, bool $includeReview = false): array
    {
        $payload = [
            'id' => $assignment->id,
            'status' => $assignment->status,
            'due_date' => $assignment->due_date,
            'completed_at' => $assignment->completed_at,
            $relation => $assignment->{$relation} ? [
                'id' => $assignment->{$relation}->id,
                'name' => $assignment->{$relation}->name,
            ] : null,
        ];

        if ($includeReview) {
            $payload['recommendation'] = $assignment->recommendation;
        }

        return $payload;
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
                    'A new peer-reviewed article is now available on ScholarlyNest.',
                    '<br><strong>Publication Details:</strong>',
                    '• <strong>Article:</strong> ' . e($article->title),
                    '• <strong>Magazine:</strong> ' . $magazineTitle,
                    '• <strong>Author:</strong> ' . $authorName,
                ];

                if ($article->abstract) {
                    $bodyLines[] = '<br><strong>Abstract:</strong>';
                    $bodyLines[] = '<div>' . nl2br(e(strip_tags((string) $article->abstract))) . '</div>';
                }

                $bodyLines[] = 'Next Action: Use the link below to read the complete article.';

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

        if (!ArticleStatus::isEditableStatus($article->status)) {
            return response()->json([
                'message' => 'This manuscript cannot be edited at its current workflow stage.',
            ], 422);
        }

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

    private function hasGlobalArticleAccess($user): bool
    {
        return $user && ($user->hasRole('super_admin') || $user->hasRole('admin'));
    }

    private function scopedAdminArticleQuery($user, $scopeUser, $observedUser = null)
    {
        $query = Article::query();

        if ($this->hasGlobalArticleAccess($user) && !$observedUser) {
            return $query;
        }

        if ($this->usesPublisherArticleScope($scopeUser)) {
            return $query->whereIn('magazine_id', $this->assignedMagazineIds($scopeUser, ['publisher']))
                ->whereIn('status', $this->publisherVisibleStatuses());
        }

        if ($this->usesMagazineArticleScope($scopeUser)) {
            return $query->whereIn('magazine_id', $this->assignedMagazineIds($scopeUser, ['editor']));
        }

        if ($scopeUser->hasRole('sub_editor')) {
            return $query->where(function ($q) use ($scopeUser) {
                $q->where('user_id', $scopeUser->id)
                    ->orWhereIn('id', function ($subQ) use ($scopeUser) {
                        $subQ->select('article_id')
                            ->from('sub_editor_assignments')
                            ->where('sub_editor_id', $scopeUser->id);
                    });
            });
        }

        if ($scopeUser->hasRole('reviewer')) {
            return $query->where(function ($q) use ($scopeUser) {
                $q->where('user_id', $scopeUser->id)
                    ->orWhereIn('id', function ($subQ) use ($scopeUser) {
                        $subQ->select('article_id')
                            ->from('reviewer_assignments')
                            ->where('reviewer_id', $scopeUser->id);
                    });
            });
        }

        if ($scopeUser->hasRole('copy_editor')) {
            return $query->where(function ($q) use ($scopeUser) {
                $q->where('user_id', $scopeUser->id)
                    ->orWhereIn('id', function ($subQ) use ($scopeUser) {
                        $subQ->select('article_id')
                            ->from('production_assignments')
                            ->where('user_id', $scopeUser->id);
                    });
            });
        }

        if ($scopeUser->hasRole('proofreader')) {
            return $query->whereIn('magazine_id', $this->assignedMagazineIds($scopeUser, ['proofreader']))
                ->whereIn('id', function ($subQ) use ($scopeUser) {
                    $subQ->select('article_id')
                        ->from('production_assignments')
                        ->where('user_id', $scopeUser->id)
                        ->where('role', 'proofreader');
                });
        }

        return $query->where('user_id', $scopeUser->id);
    }

    private function applyAdminArticleFilters($query, Request $request, bool $includeStatus = true): void
    {
        if ($includeStatus && $request->filled('status') && $request->query('status') !== 'all') {
            $statuses = collect(explode(',', (string) $request->query('status')))
                ->map(fn ($status) => trim($status))
                ->filter()
                ->flatMap(fn ($status) => ArticleStatus::queryValues($status))
                ->unique()
                ->values()
                ->all();

            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
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
    }

    private function usesMagazineArticleScope($user): bool
    {
        return $user && ($this->usesEditorialArticleScope($user) || $this->usesPublisherArticleScope($user));
    }

    private function usesEditorialArticleScope($user): bool
    {
        return $user && $user->hasRole('editor');
    }

    private function usesPublisherArticleScope($user): bool
    {
        return $user && $user->hasRole('publisher') && !$this->usesEditorialArticleScope($user);
    }

    private function publisherVisibleStatuses(): array
    {
        return array_values(array_unique(array_merge(
            ArticleStatus::queryValues(ArticleStatus::ACCEPTED),
            ArticleStatus::queryValues(ArticleStatus::COPY_EDITING),
            ArticleStatus::queryValues(ArticleStatus::PROOFREADING),
            ArticleStatus::queryValues(ArticleStatus::READY_FOR_PUBLICATION),
            ArticleStatus::queryValues(ArticleStatus::PUBLISHED)
        )));
    }

    private function assignedMagazineIds($user, array $roles): array
    {
        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
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
            ->toArray();
    }

    private function isAssignedToArticleMagazine($user, Article $article, array $roles): bool
    {
        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->unique()
            ->values()
            ->all();

        return \DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where('magazine_id', $article->magazine_id)
            ->where(function ($query) use ($normalizedRoles) {
                $query->whereIn('role', $normalizedRoles)
                    ->orWhereNull('role');
            })
            ->exists();
    }
}
