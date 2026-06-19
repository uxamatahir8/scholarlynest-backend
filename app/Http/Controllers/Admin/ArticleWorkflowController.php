<?php

namespace App\Http\Controllers\Admin;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Http\Controllers\ArticleFileController;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignReviewerRequest;
use App\Http\Requests\AssignSubEditorRequest;
use App\Http\Requests\FinalDecisionRequest;
use App\Http\Requests\MagazineIssueRequest;
use App\Http\Requests\PostPublicationActionRequest;
use App\Http\Requests\ProductionAssignmentRequest;
use App\Http\Requests\PublishArticleRequest;
use App\Http\Requests\ScreenArticleRequest;
use App\Http\Requests\SubmitReviewRequest;
use App\Http\Requests\SubmitSubEditorRecommendationRequest;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleFile;
use App\Models\EditorialDecision;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\PostPublicationAction;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\User;
use App\Services\PdfGeneratorService;
use App\Services\ArticleVersionService;
use App\Services\CitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArticleWorkflowController extends Controller
{
    public function __construct(
        private PdfGeneratorService $pdfService,
        private ArticleVersionService $versionService,
        private CitationService $citationService
    )
    {
    }

    public function context(Request $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'proofreader', 'sub_editor', 'reviewer'], false);

        $article->load([
            'issue',
            'files.uploader:id,name,email',
            'subEditorAssignments.subEditor:id,name,email',
            'reviewerAssignments.reviewer:id,name,email',
            'editorialDecisions.decider:id,name,email',
            'productionAssignments.user:id,name,email',
            'postPublicationActions.performer:id,name,email',
            'auditLogs.actor:id,name,email',
            'versions.creator:id,name,email',
            'versions.files.uploader:id,name,email',
        ]);

        $articlePayload = $this->workflowArticlePayload($article, $request->user());

        return response()->json([
            'article' => $articlePayload,
            'files' => $articlePayload['files'] ?? [],
            'versions' => $articlePayload['versions'] ?? [],
        ]);
    }

    public function versions(Request $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'proofreader', 'sub_editor', 'reviewer'], false);
        $article->load(['versions.creator:id,name,email', 'versions.files.uploader:id,name,email']);

        return response()->json([
            'data' => $this->serializedVersions($article, $request->user()),
        ]);
    }

    public function assignees(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'required|in:editor,sub_editor,reviewer,publisher,copy_editor,proofreader',
            'magazine_id' => 'nullable|exists:magazines,id',
        ]);

        $user = $request->user();
        $role = $request->query('role');
        $magazineId = $request->integer('magazine_id') ?: null;

        if (!$this->isGlobal($user) && $magazineId && !$this->isAssignedToMagazine($user, $magazineId, ['editor', 'sub_editor', 'publisher'])) {
            return response()->json(['message' => 'Forbidden. Magazine assignment required.'], 403);
        }

        $users = User::query()
            ->with('role:id,name,display_name')
            ->whereHas('role', fn ($query) => $query->where('name', $role))
            ->when($role === 'sub_editor' && !$this->isGlobal($user) && ($user->hasRole('editor') || $user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')), function ($query) use ($user) {
                $query->whereIn('users.id', function ($subQuery) use ($user) {
                    $subQuery->select('sub_editor_id')
                        ->from('editor_sub_editor')
                        ->where('editor_id', $user->id);
                });
            })
            ->when($magazineId && in_array($role, ['editor', 'publisher'], true), function ($query) use ($magazineId, $role) {
                $query->whereHas('magazines', function ($magazineQuery) use ($magazineId, $role) {
                    $magazineQuery->where('magazines.id', $magazineId)
                        ->where(function ($pivotQuery) use ($role) {
                            $pivotQuery->where('magazine_user.role', $role)
                                ->orWhereNull('magazine_user.role');
                        });
                });
            })
            ->select(['id', 'name', 'email', 'role_id'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users]);
    }

    public function mySubEditorAssignments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->isGlobal($user) && !$user->hasRole('sub_editor')) {
            return response()->json(['message' => 'Forbidden. Sub editor role required.'], 403);
        }

        $query = SubEditorAssignment::query()
            ->with($this->assignmentRelations())
            ->when(!$this->isGlobal($user), fn ($q) => $q->where('sub_editor_id', $user->id))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest();

        if ($this->isGlobal($user)) {
            $perPage = max(5, min(50, $request->integer('per_page', 15)));
            $paginator = $query->paginate($perPage);
            return response()->json([
                'data'         => collect($paginator->items())->map(fn (SubEditorAssignment $a) => $this->assignmentPayload($a, $user))->values(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
            ]);
        }

        $assignments = $query->get();
        return response()->json([
            'data'         => $assignments->map(fn (SubEditorAssignment $a) => $this->assignmentPayload($a, $user)),
            'current_page' => 1,
            'last_page'    => 1,
            'total'        => $assignments->count(),
            'per_page'     => $assignments->count(),
        ]);
    }

    public function myReviewerAssignments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->isGlobal($user) && !$user->hasRole('reviewer')) {
            return response()->json(['message' => 'Forbidden. Reviewer role required.'], 403);
        }

        $query = ReviewerAssignment::query()
            ->with($this->assignmentRelations())
            ->when(!$this->isGlobal($user), fn ($q) => $q->where('reviewer_id', $user->id))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest();

        if ($this->isGlobal($user)) {
            $perPage = max(5, min(50, $request->integer('per_page', 15)));
            $paginator = $query->paginate($perPage);
            return response()->json([
                'data'         => collect($paginator->items())->map(fn (ReviewerAssignment $a) => $this->assignmentPayload($a, $user))->values(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
            ]);
        }

        $assignments = $query->get();
        return response()->json([
            'data'         => $assignments->map(fn (ReviewerAssignment $a) => $this->assignmentPayload($a, $user)),
            'current_page' => 1,
            'last_page'    => 1,
            'total'        => $assignments->count(),
            'per_page'     => $assignments->count(),
        ]);
    }

    public function myProductionAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role');

        if ($role && !in_array($role, ['copy_editor', 'proofreader'], true)) {
            return response()->json(['message' => 'Invalid production role.'], 422);
        }

        $allowedRole = $role ?: null;
        if (!$this->isGlobal($user)) {
            if (!$user->hasRole('copy_editor') && !$user->hasRole('proofreader')) {
                return response()->json(['message' => 'Forbidden. Production role required.'], 403);
            }
            $allowedRole = $user->hasRole('copy_editor') ? 'copy_editor' : 'proofreader';
            if ($role && $role !== $allowedRole) {
                return response()->json(['message' => 'Forbidden. Production role required.'], 403);
            }
        }

        $query = ProductionAssignment::query()
            ->with($this->assignmentRelations())
            ->when(!$this->isGlobal($user), fn ($q) => $q->where('user_id', $user->id))
            ->when($allowedRole, fn ($q) => $q->where('role', $allowedRole))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest();

        if ($this->isGlobal($user)) {
            $perPage = max(5, min(50, $request->integer('per_page', 15)));
            $paginator = $query->paginate($perPage);
            return response()->json([
                'data'         => collect($paginator->items())->map(fn (ProductionAssignment $a) => $this->assignmentPayload($a, $user))->values(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
            ]);
        }

        $assignments = $query->get();
        return response()->json([
            'data'         => $assignments->map(fn (ProductionAssignment $a) => $this->assignmentPayload($a, $user)),
            'current_page' => 1,
            'last_page'    => 1,
            'total'        => $assignments->count(),
            'per_page'     => $assignments->count(),
        ]);
    }

    public function publisherDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->isGlobal($user) && !$user->hasRole('publisher')) {
            return response()->json(['message' => 'Forbidden. Publisher role required.'], 403);
        }

        $magazineIds = $this->isGlobal($user) ? null : $this->assignedMagazineIds($user, ['publisher']);

        $magazines = Magazine::query()
            ->select(['id', 'title', 'slug'])
            ->when($magazineIds !== null, fn ($query) => $query->whereIn('id', $magazineIds))
            ->orderBy('title')
            ->get();

        $articleBase = Article::with(['magazine:id,title,slug', 'issue:id,volume_number,issue_number,special_title,issue_month,issue_year', 'articleAuthors'])
            ->when($magazineIds !== null, fn ($query) => $query->whereIn('magazine_id', $magazineIds));

        $readyArticles = (clone $articleBase)
            ->whereIn('status', [ArticleStatus::ACCEPTED, ArticleStatus::READY_FOR_PUBLICATION])
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Article $article) => $this->publicationArticlePayload($article))
            ->values();

        $publishedArticles = (clone $articleBase)
            ->where('status', ArticleStatus::PUBLISHED)
            ->latest('published_at')
            ->limit(20)
            ->get()
            ->map(fn (Article $article) => $this->publicationArticlePayload($article))
            ->values();

        $issues = MagazineIssue::with('magazine:id,title,slug')
            ->withCount('articles')
            ->when($magazineIds !== null, fn ($query) => $query->whereIn('magazine_id', $magazineIds))
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (MagazineIssue $issue) => $this->issuePayload($issue))
            ->values();

        return response()->json([
            'magazines' => $magazines,
            'ready_articles' => $readyArticles,
            'published_articles' => $publishedArticles,
            'eligible_articles' => $readyArticles,
            'issues' => $issues,
            'counts' => [
                'magazines' => $magazines->count(),
                'ready_articles' => $readyArticles->count(),
                'published_articles' => $publishedArticles->count(),
                'issues' => $issues->count(),
            ],
        ]);
    }

    public function screen(ScreenArticleRequest $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor']);
        $oldStatus = $article->status;

        $storedFile = null;

        DB::transaction(function () use ($request, $article, $oldStatus, &$storedFile) {
            $nextStatus = $request->decision === 'reject'
                ? ArticleStatus::REJECTED
                : ArticleStatus::UNDER_REVIEW;

            if ($request->hasFile('plagiarism_report')) {
                $storedFile = app(ArticleFileController::class)->storeUploadedFile($article, $request->file('plagiarism_report'), ArticleFile::PLAGIARISM_REPORT, $request->user()->id);
            }

            $article->update([
                'status' => $nextStatus,
                'plagiarism_status' => $request->plagiarism_status,
                'plagiarism_score' => $request->plagiarism_score,
                'plagiarism_report_path' => $storedFile?->file_path ?? $request->plagiarism_report_path,
                'screened_at' => now(),
                'screened_by' => $request->user()->id,
                'rejection_reason' => $request->decision === 'reject' ? $request->comments : null,
            ]);

            $this->audit($article, $request->user()->id, 'article.screened', $oldStatus, $nextStatus, $request->validated());
        });

        return response()->json([
            'message' => 'Article screening recorded.',
            'article' => $article->fresh(),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function assignSubEditor(AssignSubEditorRequest $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor']);
        $oldStatus = $article->status;

        $assignment = DB::transaction(function () use ($request, $article, $oldStatus) {
            $assignment = SubEditorAssignment::updateOrCreate(
                [
                    'article_id' => $article->id,
                    'sub_editor_id' => $request->sub_editor_id,
                ],
                [
                    'assigned_by' => $request->user()->id,
                    'status' => 'pending',
                    'due_date' => $request->due_date,
                    'completed_at' => null,
                ]
            );

            $article->update(['status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR]);
            $this->audit($article, $request->user()->id, 'sub_editor.assigned', $oldStatus, ArticleStatus::ASSIGNED_TO_SUB_EDITOR, [
                'sub_editor_id' => $request->sub_editor_id,
                'due_date' => $request->due_date,
            ]);

            return $assignment;
        });

        $assignment->load('subEditor:id,name,email');
        event(new ArticleWorkflowEventOccurred($article->fresh(), 'sub_editor.assigned', $request->user(), [
            'sub_editor' => $assignment->subEditor,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
        ]));

        return response()->json([
            'message' => 'Sub editor assigned.',
            'assignment' => $assignment,
            'article' => $article->fresh(),
        ], 201);
    }

    public function assignReviewer(AssignReviewerRequest $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'sub_editor']);
        $oldStatus = $article->status;

        $assignment = DB::transaction(function () use ($request, $article, $oldStatus) {
            $assignment = ReviewerAssignment::updateOrCreate(
                [
                    'article_id' => $article->id,
                    'reviewer_id' => $request->reviewer_id,
                ],
                [
                    'sub_editor_assignment_id' => $request->sub_editor_assignment_id,
                    'assigned_by' => $request->user()->id,
                    'status' => 'pending',
                    'due_date' => $request->due_date,
                    'accepted_at' => null,
                    'completed_at' => null,
                ]
            );

            $article->update(['status' => ArticleStatus::REVIEWER_ASSIGNED]);
            $this->audit($article, $request->user()->id, 'reviewer.assigned', $oldStatus, ArticleStatus::REVIEWER_ASSIGNED, [
                'reviewer_id' => $request->reviewer_id,
                'due_date' => $request->due_date,
            ]);

            return $assignment;
        });

        $assignment->load('reviewer:id,name,email');
        event(new ArticleWorkflowEventOccurred($article->fresh(), 'reviewer.assigned', $request->user(), [
            'reviewer' => $assignment->reviewer,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::REVIEWER_ASSIGNED,
        ]));

        return response()->json([
            'message' => 'Reviewer assigned.',
            'assignment' => $assignment,
            'article' => $article->fresh(),
        ], 201);
    }

    public function submitSubEditorRecommendation(SubmitSubEditorRecommendationRequest $request, int $assignmentId): JsonResponse
    {
        $assignment = SubEditorAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (!$this->isGlobal($user) && (int) $assignment->sub_editor_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Sub editor assignment required.'], 403);
        }

        $oldStatus = $assignment->article->status;

        $storedFile = null;

        DB::transaction(function () use ($request, $assignment, $oldStatus, &$storedFile) {
            if ($request->hasFile('annotated_manuscript')) {
                $storedFile = app(ArticleFileController::class)->storeUploadedFile($assignment->article, $request->file('annotated_manuscript'), ArticleFile::ANNOTATED_MANUSCRIPT, $request->user()->id, [
                    'assignment_type' => 'sub_editor_assignment',
                    'assignment_id' => $assignment->id,
                ]);
            }

            $assignment->update([
                'status' => 'completed',
                'recommendation' => $request->recommendation,
                'comments' => trim(($request->comments ?? '') . "\n\nInternal notes:\n" . ($request->internal_notes ?? '')),
                'completed_at' => now(),
            ]);

            $assignment->article->update(['status' => ArticleStatus::REVIEW_IN_PROGRESS]);
            $this->audit($assignment->article, $request->user()->id, 'sub_editor.recommendation_submitted', $oldStatus, ArticleStatus::REVIEW_IN_PROGRESS, [
                'sub_editor_assignment_id' => $assignment->id,
                'recommendation' => $request->recommendation,
            ]);
        });

        event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'sub_editor.recommendation_submitted', $request->user(), [
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
        ]));

        return response()->json([
            'message' => 'Sub editor recommendation submitted.',
            'assignment' => $assignment->fresh(),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function acceptReviewerAssignment(Request $request, int $assignmentId): JsonResponse
    {
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (!$this->isGlobal($user) && (int) $assignment->reviewer_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Reviewer assignment required.'], 403);
        }

        $oldStatus = $assignment->article->status;

        DB::transaction(function () use ($request, $assignment, $oldStatus) {
            $assignment->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            $assignment->article->update(['status' => ArticleStatus::REVIEW_IN_PROGRESS]);
            $this->audit($assignment->article, $request->user()->id, 'review.accepted', $oldStatus, ArticleStatus::REVIEW_IN_PROGRESS, [
                'reviewer_assignment_id' => $assignment->id,
            ]);
        });

        event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.accepted', $request->user(), [
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
        ]));

        return response()->json([
            'message' => 'Reviewer assignment accepted.',
            'assignment' => $assignment->fresh(),
            'article' => $assignment->article->fresh(),
        ]);
    }

    public function submitReview(SubmitReviewRequest $request, int $assignmentId): JsonResponse
    {
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (!$this->isGlobal($user) && (int) $assignment->reviewer_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Reviewer assignment required.'], 403);
        }

        $oldStatus = $assignment->article->status;

        $storedFile = null;

        DB::transaction(function () use ($request, $assignment, $oldStatus, &$storedFile) {
            if ($request->hasFile('reviewed_manuscript')) {
                $storedFile = app(ArticleFileController::class)->storeUploadedFile($assignment->article, $request->file('reviewed_manuscript'), ArticleFile::REVIEWED_MANUSCRIPT, $request->user()->id, [
                    'assignment_type' => 'reviewer_assignment',
                    'assignment_id' => $assignment->id,
                ]);
            }

            $assignment->update([
                'status' => 'completed',
                'scorecard' => $request->scorecard,
                'recommendation' => $request->recommendation,
                'comments_for_author' => $request->comments_for_author,
                'confidential_comments' => $request->confidential_comments,
                'completed_at' => now(),
            ]);

            $assignment->article->update(['status' => ArticleStatus::REVIEW_IN_PROGRESS]);
            $this->audit($assignment->article, $request->user()->id, 'review.submitted', $oldStatus, ArticleStatus::REVIEW_IN_PROGRESS, [
                'reviewer_assignment_id' => $assignment->id,
                'recommendation' => $request->recommendation,
            ]);
        });

        event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.submitted', $request->user(), [
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
        ]));

        return response()->json([
            'message' => 'Review submitted.',
            'assignment' => $assignment->fresh(),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function reopenReviewer(Request $request, int $assignmentId): JsonResponse
    {
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $article = $this->findAuthorizedArticle($request, $assignment->article_id, ['editor']);

        $assignment->update([
            'status' => 'reopened',
            'completed_at' => null,
        ]);

        $this->audit($article, $request->user()->id, 'review.reopened', $article->status, $article->status, [
            'reviewer_assignment_id' => $assignment->id,
        ]);

        event(new ArticleWorkflowEventOccurred($article->fresh(), 'review.reopened', $request->user(), [
            'assignment_id' => $assignment->id,
            'from_status' => $article->status,
            'to_status' => $article->status,
        ]));

        return response()->json([
            'message' => 'Review assignment reopened.',
            'assignment' => $assignment->fresh(),
        ]);
    }

    public function finalDecision(FinalDecisionRequest $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor']);
        $oldStatus = $article->status;

        $decisionStatus = match ($request->decision) {
            'accepted' => ArticleStatus::ACCEPTED,
            'rejected' => ArticleStatus::REJECTED,
            'minor_revision' => ArticleStatus::MINOR_REVISION_REQUIRED,
            'major_revision' => ArticleStatus::MAJOR_REVISION_REQUIRED,
        };

        $decision = DB::transaction(function () use ($request, $article, $oldStatus, $decisionStatus) {
            $decision = EditorialDecision::create([
                'article_id' => $article->id,
                'decision_by' => $request->user()->id,
                'decision' => $request->decision,
                'decision_source' => $request->decision_source,
                'decision_date' => now(),
                'comments_for_author' => $request->comments_for_author,
                'internal_notes' => $request->internal_notes,
            ]);

            $article->update([
                'status' => $decisionStatus,
                'rejection_reason' => $decisionStatus === ArticleStatus::REJECTED ? $request->comments_for_author : null,
                'published_at' => $decisionStatus === ArticleStatus::ACCEPTED && !$article->published_at ? now() : $article->published_at,
            ]);

            $this->audit($article, $request->user()->id, 'editorial.decision', $oldStatus, $decisionStatus, $request->validated());

            return $decision;
        });

        $decisionEvent = match ($decisionStatus) {
            ArticleStatus::ACCEPTED => 'article.accepted',
            ArticleStatus::REJECTED => 'article.rejected',
            ArticleStatus::REVISION_REQUIRED, ArticleStatus::MINOR_REVISION_REQUIRED, ArticleStatus::MAJOR_REVISION_REQUIRED => 'revision.requested',
            default => 'editorial.decision',
        };

        event(new ArticleWorkflowEventOccurred($article->fresh(), $decisionEvent, $request->user(), [
            'decision_id' => $decision->id,
            'from_status' => $oldStatus,
            'to_status' => $decisionStatus,
        ]));

        if ($decisionStatus === ArticleStatus::ACCEPTED) {
            $this->versionService->createSnapshot(
                $article->fresh(['articleAuthors', 'tags', 'files']),
                $request->user(),
                'Accepted Manuscript',
                $request->comments_for_author
            );
        }

        return response()->json([
            'message' => 'Editorial decision recorded.',
            'decision' => $decision,
            'article' => $article->fresh(),
        ], 201);
    }

    public function assignProduction(ProductionAssignmentRequest $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher']);
        $oldStatus = $article->status;
        $nextStatus = $request->role === 'copy_editor' ? ArticleStatus::COPY_EDITING : ArticleStatus::PROOFREADING;

        $assignment = DB::transaction(function () use ($request, $article, $oldStatus, $nextStatus) {
            $assignment = ProductionAssignment::updateOrCreate(
                [
                    'article_id' => $article->id,
                    'user_id' => $request->user_id,
                    'role' => $request->role,
                ],
                [
                    'assigned_by' => $request->user()->id,
                    'status' => 'pending',
                    'due_date' => $request->due_date,
                    'completed_at' => null,
                ]
            );

            $article->update(['status' => $nextStatus]);
            $this->audit($article, $request->user()->id, 'production.assigned', $oldStatus, $nextStatus, $request->validated());

            return $assignment;
        });

        $assignment->load('user:id,name,email');
        event(new ArticleWorkflowEventOccurred($article->fresh(), 'production.assigned', $request->user(), [
            'assignee' => $assignment->user,
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => $nextStatus,
        ]));

        return response()->json([
            'message' => 'Production assignment created.',
            'assignment' => $assignment,
            'article' => $article->fresh(),
        ], 201);
    }

    public function completeProduction(Request $request, int $assignmentId): JsonResponse
    {
        $request->validate([
            'production_file' => 'nullable|file|mimes:pdf,doc,docx|max:25600',
        ]);

        $assignment = ProductionAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (!$this->isGlobal($user) && (int) $assignment->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Production assignment required.'], 403);
        }

        $storedFile = null;
        $oldStatus = $assignment->article->status;

        if ($request->hasFile('production_file')) {
            $storedFile = app(ArticleFileController::class)->storeUploadedFile(
                $assignment->article,
                $request->file('production_file'),
                $assignment->role === 'proofreader' ? ArticleFile::PROOF_FILE : ArticleFile::COPY_EDITED_FILE,
                $user->id,
                [
                    'assignment_type' => 'production_assignment',
                    'assignment_id' => $assignment->id,
                ]
            );
        }

        $assignment->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $assignment->article->update(['status' => ArticleStatus::READY_FOR_PUBLICATION]);
        $this->audit($assignment->article, $user->id, 'production.completed', $oldStatus, ArticleStatus::READY_FOR_PUBLICATION, [
            'production_assignment_id' => $assignment->id,
        ]);

        $freshArticle = $assignment->article->fresh();
        event(new ArticleWorkflowEventOccurred($freshArticle, 'production.completed', $user, [
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::READY_FOR_PUBLICATION,
        ]));
        event(new ArticleWorkflowEventOccurred($freshArticle, 'article.ready_for_publication', $user, [
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::READY_FOR_PUBLICATION,
        ]));

        return response()->json([
            'message' => 'Production assignment completed.',
            'assignment' => $assignment->fresh(),
            'article' => $assignment->article->fresh(),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function issues(Request $request): JsonResponse
    {
        $query = MagazineIssue::with('magazine:id,title,slug')
            ->withCount('articles')
            ->orderByDesc('created_at');

        if (!$this->isGlobal($request->user())) {
            $query->whereIn('magazine_id', $this->assignedMagazineIds($request->user(), ['publisher']));
        }

        if ($request->filled('magazine_id')) {
            $query->where('magazine_id', $request->integer('magazine_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function issueMagazines(Request $request): JsonResponse
    {
        $query = Magazine::query()->select(['id', 'title', 'slug'])->orderBy('title');

        if (!$this->isGlobal($request->user())) {
            $query->whereIn('id', $this->assignedMagazineIds($request->user(), ['publisher']));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function showIssue(Request $request, int $issueId): JsonResponse
    {
        $issue = MagazineIssue::with([
            'magazine:id,title,slug',
            'articles' => fn ($query) => $query->with('user:id,name,email')->orderBy('page_start')->orderBy('title'),
        ])->withCount('articles')->findOrFail($issueId);

        if (!$this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        return response()->json(['issue' => $this->issuePayload($issue)]);
    }

    public function storeIssue(MagazineIssueRequest $request): JsonResponse
    {
        if (!$this->isGlobal($request->user()) && !$this->isAssignedToMagazine($request->user(), $request->magazine_id, ['publisher'])) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        $issue = MagazineIssue::create($this->issueData($request));

        return response()->json([
            'message' => 'Magazine issue created.',
            'issue' => $this->issuePayload($issue->load('magazine:id,title,slug')->loadCount('articles')),
        ], 201);
    }

    public function updateIssue(MagazineIssueRequest $request, int $issueId): JsonResponse
    {
        $issue = MagazineIssue::findOrFail($issueId);

        if (!$this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        if ((int) $issue->magazine_id !== (int) $request->integer('magazine_id')
            && !$this->isGlobal($request->user())
            && !$this->isAssignedToMagazine($request->user(), $request->integer('magazine_id'), ['publisher'])) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required for target magazine.'], 403);
        }

        $issue->update($this->issueData($request, $issue));

        return response()->json([
            'message' => 'Magazine issue updated.',
            'issue' => $this->issuePayload($issue->fresh(['magazine:id,title,slug'])->loadCount('articles')),
        ]);
    }

    public function publishIssue(Request $request, int $issueId): JsonResponse
    {
        $issue = MagazineIssue::findOrFail($issueId);

        if (!$this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        $issue->update([
            'status' => 'published',
            'is_published' => true,
            'published_at' => $issue->published_at ?: now(),
        ]);

        return response()->json([
            'message' => 'Magazine issue published.',
            'issue' => $this->issuePayload($issue->fresh(['magazine:id,title,slug'])->loadCount('articles')),
        ]);
    }

    public function unpublishIssue(Request $request, int $issueId): JsonResponse
    {
        $issue = MagazineIssue::findOrFail($issueId);

        if (!$this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        $issue->update([
            'status' => 'unpublished',
            'is_published' => false,
            'published_at' => null,
        ]);

        return response()->json([
            'message' => 'Magazine issue unpublished.',
            'issue' => $this->issuePayload($issue->fresh(['magazine:id,title,slug'])->loadCount('articles')),
        ]);
    }

    public function eligibleIssueArticles(Request $request): JsonResponse
    {
        $request->validate([
            'magazine_id' => 'nullable|exists:magazines,id',
            'issue_id' => 'nullable|exists:magazine_issues,id',
        ]);

        $magazineId = $request->integer('magazine_id') ?: null;
        if ($request->filled('issue_id')) {
            $issue = MagazineIssue::findOrFail($request->integer('issue_id'));
            if (!$this->canManageIssue($request->user(), $issue)) {
                return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
            }
            $magazineId = $issue->magazine_id;
        } elseif (!$this->isGlobal($request->user()) && $magazineId && !$this->isAssignedToMagazine($request->user(), $magazineId, ['publisher'])) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        $query = Article::with(['magazine:id,title,slug', 'issue:id,volume_number,issue_number,special_title'])
            ->whereIn('status', [
                ArticleStatus::ACCEPTED,
                ArticleStatus::READY_FOR_PUBLICATION,
                ArticleStatus::PUBLISHED,
            ])
            ->orderByDesc('updated_at');

        if ($magazineId) {
            $query->where('magazine_id', $magazineId);
        } elseif (!$this->isGlobal($request->user())) {
            $query->whereIn('magazine_id', $this->assignedMagazineIds($request->user(), ['publisher']));
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (Article $article) => $this->publicationArticlePayload($article))->values()]);
    }

    public function publish(PublishArticleRequest $request, int $articleId): JsonResponse
    {
        $request->validate([
            'publication_pdf' => 'nullable|file|mimes:pdf|max:25600',
        ]);

        $article = $this->findAuthorizedArticle($request, $articleId, ['publisher']);
        $oldStatus = $article->status;
        $storedFile = null;
        $issue = $request->magazine_issue_id ? MagazineIssue::findOrFail($request->magazine_issue_id) : null;

        if (!in_array(ArticleStatus::normalize($article->status), [ArticleStatus::ACCEPTED, ArticleStatus::READY_FOR_PUBLICATION, ArticleStatus::PUBLISHED], true)) {
            return response()->json(['message' => 'Only accepted or ready-for-publication articles can be published.'], 422);
        }

        if ($issue && (int) $issue->magazine_id !== (int) $article->magazine_id) {
            return response()->json(['message' => 'The selected issue does not belong to this article magazine.'], 422);
        }

        if ($issue && !$this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required for selected issue.'], 403);
        }

        DB::transaction(function () use ($request, $article, $oldStatus, &$storedFile) {
            if ($request->hasFile('publication_pdf')) {
                $storedFile = app(ArticleFileController::class)->storeUploadedFile($article, $request->file('publication_pdf'), ArticleFile::PUBLICATION_PDF, $request->user()->id);
                $article->pdf_path = $storedFile->file_path;
            }

            $article->update([
                'status' => ArticleStatus::PUBLISHED,
                'magazine_issue_id' => $request->magazine_issue_id,
                'doi' => $request->doi,
                'published_year' => $request->published_year,
                'published_month' => $request->published_month,
                'page_start' => $request->page_start,
                'page_end' => $request->page_end,
                'published_at' => now(),
            ]);

            if (empty($article->pdf_path)) {
                $article->pdf_path = $this->pdfService->generate($article);
                $article->save();
            }

            $this->audit($article, $request->user()->id, 'article.published', $oldStatus, ArticleStatus::PUBLISHED, $request->validated());
        });

        event(new ArticleWorkflowEventOccurred($article->fresh(), 'article.published', $request->user(), [
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::PUBLISHED,
        ]));

        if ($storedFile) {
            $this->versionService->createSnapshot(
                $article->fresh(['articleAuthors', 'tags', 'files']),
                $request->user(),
                'Published Manuscript',
                'Publication-ready manuscript snapshot.',
                null,
                [$storedFile->id]
            );
        }

        return response()->json([
            'message' => 'Article published.',
            'article' => $this->publicationArticlePayload($article->fresh(['magazine', 'issue', 'articleAuthors'])),
            'citation' => [
                'format' => 'APA',
                'text' => $this->citationService->apa($article->fresh(['magazine', 'issue', 'articleAuthors'])),
            ],
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function postPublication(PostPublicationActionRequest $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['publisher']);
        $oldStatus = $article->status;

        $nextStatus = $article->status;

        $action = DB::transaction(function () use ($request, $article, $oldStatus, &$nextStatus) {
            $action = PostPublicationAction::create([
                'article_id' => $article->id,
                'action_type' => $request->action_type,
                'reason' => $request->reason,
                'notice_text' => $request->notice_text,
                'performed_by' => $request->user()->id,
                'approved_by' => $request->approved_by,
            ]);

            $nextStatus = match ($request->action_type) {
                'archive' => ArticleStatus::ARCHIVED,
                'unpublish', 'retraction' => ArticleStatus::WITHDRAWN,
                default => $article->status,
            };

            if ($nextStatus !== $article->status) {
                $article->update(['status' => $nextStatus]);
            }

            $this->audit($article, $request->user()->id, 'post_publication.' . $request->action_type, $oldStatus, $nextStatus, $request->validated());

            return $action;
        });

        event(new ArticleWorkflowEventOccurred($article->fresh(), 'post_publication.recorded', $request->user(), [
            'action_id' => $action->id,
            'action_type' => $request->action_type,
            'from_status' => $oldStatus,
            'to_status' => $nextStatus,
        ]));

        return response()->json([
            'message' => 'Post-publication action recorded.',
            'action' => $action,
            'article' => $article->fresh(),
        ], 201);
    }

    public function auditLogs(Request $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor'], false);

        return response()->json([
            'data' => $article->auditLogs()->with('actor:id,name,email')->latest()->paginate($request->integer('per_page', 25)),
        ]);
    }

    private function issueData(MagazineIssueRequest $request, ?MagazineIssue $existing = null): array
    {
        $validated = $request->validated();
        $status = $validated['status'] ?? ($request->boolean('is_published') ? 'published' : ($existing?->status ?? 'draft'));
        $isPublished = $status === 'published' || (bool) ($validated['is_published'] ?? false);
        $coverImage = $existing?->cover_image;

        if ($request->hasFile('cover_image')) {
            if ($coverImage) {
                Storage::disk('public')->delete(str_replace('storage/', '', $coverImage));
            }
            $coverImage = 'storage/' . $request->file('cover_image')->store('magazine-issues', 'public');
        }

        return [
            'magazine_id' => $validated['magazine_id'],
            'volume_number' => $validated['volume_number'],
            'issue_number' => $validated['issue_number'],
            'issue_month' => $validated['issue_month'] ?? null,
            'issue_year' => $validated['issue_year'] ?? null,
            'special_title' => $validated['special_title'] ?? null,
            'description' => $validated['description'] ?? null,
            'cover_image' => $coverImage,
            'status' => $isPublished ? 'published' : $status,
            'is_published' => $isPublished,
            'published_at' => $isPublished ? ($validated['published_at'] ?? $existing?->published_at ?? now()) : null,
        ];
    }

    private function issuePayload(MagazineIssue $issue): array
    {
        $issue->loadMissing('magazine:id,title,slug');

        return [
            'id' => $issue->id,
            'magazine_id' => $issue->magazine_id,
            'magazine' => $issue->magazine,
            'volume_number' => $issue->volume_number,
            'issue_number' => $issue->issue_number,
            'issue_month' => $issue->issue_month,
            'issue_year' => $issue->issue_year,
            'special_title' => $issue->special_title,
            'description' => $issue->description,
            'cover_image' => $issue->cover_image,
            'status' => $issue->status ?: ($issue->is_published ? 'published' : 'draft'),
            'is_published' => $issue->is_published,
            'published_at' => $issue->published_at,
            'articles_count' => $issue->articles_count ?? $issue->articles()->count(),
            'articles' => $issue->relationLoaded('articles')
                ? $issue->articles->map(fn (Article $article) => $this->publicationArticlePayload($article))->values()
                : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
        ];
    }

    private function publicationArticlePayload(Article $article): array
    {
        $article->loadMissing(['magazine:id,title,slug', 'issue:id,volume_number,issue_number,special_title,issue_month,issue_year', 'articleAuthors']);

        $data = $article->toArray();
        $data['article_url'] = $this->articleUrl($article);
        $data['pdf_url'] = $article->pdf_path ? url("/api/articles/{$article->id}/download-pdf") : null;
        $data['citation'] = [
            'format' => 'APA',
            'text' => $this->citationService->apa($article),
        ];

        return $data;
    }

    private function articleUrl(Article $article): string
    {
        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/');
        $magazineSlug = $article->magazine?->slug;

        return $magazineSlug
            ? "{$frontendUrl}/magazines/{$magazineSlug}/articles/{$article->slug}"
            : "{$frontendUrl}/articles/{$article->slug}";
    }

    private function canManageIssue($user, MagazineIssue $issue): bool
    {
        return $this->isGlobal($user) || $this->isAssignedToMagazine($user, $issue->magazine_id, ['publisher']);
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

        return DB::table('magazine_user')
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

    private function findAuthorizedArticle(Request $request, int $articleId, array $roles, bool $requireAssignedRole = true): Article
    {
        $article = Article::findOrFail($articleId);
        $user = $request->user();

        if ($this->isGlobal($user)) {
            return $article;
        }

        if (!$requireAssignedRole && $article->user_id === $user->id) {
            return $article;
        }

        if (!$requireAssignedRole && $this->isArticleAuthorRecord($user, $article)) {
            return $article;
        }

        if (in_array('sub_editor', $roles, true) && $this->hasSubEditorAssignment($user, $article)) {
            return $article;
        }

        if (in_array('reviewer', $roles, true) && $this->hasReviewerAssignment($user, $article)) {
            return $article;
        }

        if ((in_array('copy_editor', $roles, true) || in_array('proofreader', $roles, true)) && $this->hasProductionAssignment($user, $article)) {
            return $article;
        }

        if (in_array('publisher', $roles, true)
            && $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher'])
            && in_array(ArticleStatus::normalize($article->status), [
                ArticleStatus::ACCEPTED,
                ArticleStatus::COPY_EDITING,
                ArticleStatus::PROOFREADING,
                ArticleStatus::READY_FOR_PUBLICATION,
                ArticleStatus::PUBLISHED,
            ], true)) {
            return $article;
        }

        if ($this->isAssignedToMagazine($user, $article->magazine_id, $roles)) {
            return $article;
        }

        throw new HttpResponseException(response()->json(['message' => 'Forbidden. Magazine assignment required.'], 403));
    }

    private function assignmentRelations(): array
    {
        return [
            'article.issue',
            'article.magazine:id,title,slug',
            'article.files.uploader:id,name,email',
            'article.subEditorAssignments.subEditor:id,name,email',
            'article.reviewerAssignments.reviewer:id,name,email',
            'article.editorialDecisions.decider:id,name,email',
            'article.productionAssignments.user:id,name,email',
            'article.postPublicationActions.performer:id,name,email',
            'article.auditLogs.actor:id,name,email',
            'article.versions.creator:id,name,email',
            'article.versions.files.uploader:id,name,email',
        ];
    }

    private function serializedVersions(Article $article, $user): array
    {
        return $article->versions
            ->sortByDesc('version_number')
            ->map(fn ($version) => $this->versionService->serializeVersion($version, $user))
            ->values()
            ->all();
    }

    private function workflowArticlePayload(Article $article, $user): array
    {
        $data = $article->toArray();
        $data['files'] = app(ArticleFileController::class)->filterVisibleFiles($user, $article->files);
        $data['versions'] = $this->serializedVersions($article, $user);

        $canViewEditorial = $this->canViewEditorialInternals($user, $article);
        $canViewReviewWorkflow = $canViewEditorial || $this->hasSubEditorAssignment($user, $article);
        $canViewPublication = $canViewEditorial || $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher']);
        $canViewProduction = $canViewPublication || $this->hasProductionAssignment($user, $article);

        $data['audit_logs'] = $this->canViewAuditLogs($user, $article)
            ? ($data['audit_logs'] ?? [])
            : [];

        $data['sub_editor_assignments'] = collect($data['sub_editor_assignments'] ?? [])
            ->filter(function (array $assignment) use ($user, $canViewEditorial) {
                return $canViewEditorial || (int) ($assignment['sub_editor_id'] ?? 0) === (int) ($user?->id ?? 0);
            })
            ->values()
            ->all();

        $data['reviewer_assignments'] = collect($data['reviewer_assignments'] ?? [])
            ->filter(function (array $assignment) use ($user, $canViewReviewWorkflow) {
                return $canViewReviewWorkflow || (int) ($assignment['reviewer_id'] ?? 0) === (int) ($user?->id ?? 0);
            })
            ->map(function (array $assignment) use ($user, $canViewEditorial) {
                $isOwnReviewer = (int) ($assignment['reviewer_id'] ?? 0) === (int) ($user?->id ?? 0);
                if (!$canViewEditorial && !$isOwnReviewer) {
                    unset($assignment['confidential_comments']);
                }
                if (!$canViewEditorial && !$isOwnReviewer) {
                    unset($assignment['reviewer']);
                }
                return $assignment;
            })
            ->values()
            ->all();

        $data['editorial_decisions'] = collect($data['editorial_decisions'] ?? [])
            ->map(function (array $decision) use ($canViewEditorial) {
                if (!$canViewEditorial) {
                    unset($decision['internal_notes']);
                }
                return $decision;
            })
            ->values()
            ->all();

        $data['production_assignments'] = collect($data['production_assignments'] ?? [])
            ->filter(function (array $assignment) use ($user, $canViewProduction) {
                return $canViewProduction || (int) ($assignment['user_id'] ?? 0) === (int) ($user?->id ?? 0);
            })
            ->values()
            ->all();

        if (!$canViewPublication && !$this->hasProductionAssignment($user, $article) && !$this->isArticleAuthorRecord($user, $article) && (int) $article->user_id !== (int) ($user?->id ?? 0)) {
            $data['post_publication_actions'] = [];
        }

        return $data;
    }

    private function canViewAuditLogs($user, Article $article): bool
    {
        return $this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor', 'magazine_editor']);
    }

    private function canViewEditorialInternals($user, Article $article): bool
    {
        return $this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor', 'magazine_editor']);
    }

    private function assignmentPayload(SubEditorAssignment|ReviewerAssignment|ProductionAssignment $assignment, $user): array
    {
        $article = $assignment->article;
        $articleData = $this->workflowArticlePayload($article, $user);
        $visibleFiles = $articleData['files'] ?? [];

        $assignmentData = $assignment->toArray();
        $assignmentData['article'] = $articleData;
        $assignmentData['files'] = $visibleFiles;
        $assignmentData['is_overdue'] = $assignment->due_date
            && $assignment->due_date->isPast()
            && !in_array($assignment->status, ['completed'], true);

        return $assignmentData;
    }

    private function isGlobal($user): bool
    {
        return $user && ($user->hasRole('super_admin') || $user->hasRole('admin'));
    }

    private function isAssignedToMagazine($user, int $magazineId, array $roles): bool
    {
        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->when(in_array('magazine_editor', $roles, true), fn ($collection) => $collection->push('editor'))
            ->unique()
            ->values()
            ->all();

        return DB::table('magazine_user')
            ->where('user_id', $user->id)
            ->where('magazine_id', $magazineId)
            ->where(function ($query) use ($normalizedRoles) {
                $query->whereIn('role', $normalizedRoles)
                    ->orWhereNull('role');
            })
            ->exists();
    }

    private function isArticleAuthorRecord($user, Article $article): bool
    {
        return DB::table('article_author')
            ->where('article_id', $article->id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('co_author_email', $user->email);
            })
            ->exists();
    }

    private function hasSubEditorAssignment($user, Article $article): bool
    {
        return DB::table('sub_editor_assignments')
            ->where('article_id', $article->id)
            ->where('sub_editor_id', $user->id)
            ->exists();
    }

    private function hasReviewerAssignment($user, Article $article): bool
    {
        return DB::table('reviewer_assignments')
            ->where('article_id', $article->id)
            ->where('reviewer_id', $user->id)
            ->exists();
    }

    private function hasProductionAssignment($user, Article $article): bool
    {
        return DB::table('production_assignments')
            ->where('article_id', $article->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function audit(Article $article, ?int $actorId, string $event, ?string $fromStatus, ?string $toStatus, array $payload = []): void
    {
        ArticleAuditLog::create([
            'article_id' => $article->id,
            'actor_id' => $actorId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'payload' => $this->sanitizeAuditPayload($payload),
        ]);
    }

    private function sanitizeAuditPayload(array $payload): array
    {
        return collect($payload)
            ->map(function ($value) {
                if ($value instanceof UploadedFile) {
                    return [
                        'original_name' => $value->getClientOriginalName(),
                        'mime_type' => $value->getMimeType(),
                        'size' => $value->getSize(),
                    ];
                }

                return $value;
            })
            ->all();
    }
}
