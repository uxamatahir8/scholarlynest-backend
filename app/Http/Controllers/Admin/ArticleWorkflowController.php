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
use App\Models\ArticlePublicationSection;
use App\Models\ArticleReviewerPreference;
use App\Models\EditorialDecision;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\MediaUploadSession;
use App\Models\PostPublicationAction;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\ReviewQuestionnaireInstance;
use App\Models\ReviewQuestionnaireVersion;
use App\Models\Role;
use App\Models\SubEditorAssignment;
use App\Models\User;
use App\Services\PdfGeneratorService;
use App\Services\ArticleVersionService;
use App\Services\CitationService;
use App\Services\Media\CleanUploadResolver;
use App\Services\Media\MediaStorageService;
use App\Services\PasswordSetupService;
use App\Services\Security\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleWorkflowController extends Controller
{
    public function __construct(
        private PdfGeneratorService $pdfService,
        private ArticleVersionService $versionService,
        private CitationService $citationService,
        private PasswordSetupService $passwordSetupService
    )
    {
    }

    public function context(Request $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'sub_editor', 'reviewer'], false);

        $article->load([
            'issue',
            'articleAuthors',
            'reviewerPreferences',
            'publicationSections',
            'assets',
            'files.uploader:id,name',
            'subEditorAssignments.subEditor:id,name',
            'reviewerAssignments.reviewer:id,name,email',
            'reviewerAssignments.questionnaireInstance.version.questions.options',
            'reviewerAssignments.questionnaireInstance.responses',
            'editorialDecisions.decider:id,name',
            'productionAssignments.user:id,name',
            'postPublicationActions.performer:id,name',
            'auditLogs.actor:id,name',
            'versions.creator:id,name',
            'versions.files.uploader:id,name',
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
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'sub_editor', 'reviewer'], false);
        $article->load(['versions.creator:id,name', 'versions.files.uploader:id,name']);

        return response()->json([
            'data' => $this->serializedVersions($article, $request->user()),
        ]);
    }

    public function assignees(Request $request): JsonResponse
    {
        $request->validate([
            'role' => 'required|in:editor,sub_editor,reviewer,publisher,copy_editor',
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
            ->when($role === 'sub_editor', function ($query) use ($user) {
                // Exclude orphan Sub Editors (those with zero Editor links)
                $query->whereIn('users.id', function ($subQuery) {
                    $subQuery->select('sub_editor_id')
                        ->from('editor_sub_editor');
                });

                // Editors see only Sub Editors linked to them
                if (!$this->isGlobal($user) && ($user->hasRole('editor') || $user->hasRole('magazine_editor') || $user->hasRole('magazine-editor'))) {
                    $query->whereIn('users.id', function ($subQuery) use ($user) {
                        $subQuery->select('sub_editor_id')
                            ->from('editor_sub_editor')
                            ->where('editor_id', $user->id);
                    });
                }
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
            ->select(['id', 'name', 'role_id'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $assignee) => $this->assigneePayload($assignee))->values(),
        ]);
    }

    public function mySubEditorAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $observedUser = DeskObserverController::resolveObservedUser($request, 'sub_editor');
        $deskUser = $observedUser ?: $user;

        if (!$observedUser && !$this->isGlobal($user) && !$user->hasRole('sub_editor')) {
            return response()->json(['message' => 'Forbidden. Sub editor role required.'], 403);
        }

        $status = $request->query('status');
        $search = trim((string) $request->query('search'));

        $query = SubEditorAssignment::query()
            ->with([
                'article:id,magazine_id,title,slug,status,created_at,updated_at',
                'article.magazine:id,title,slug',
            ])
            ->when($observedUser || !$this->isGlobal($user), fn ($q) => $q->where('sub_editor_id', $deskUser->id))
            ->when($status === 'active', fn ($q) => $q->whereNull('completed_at')->where('status', '!=', 'completed'))
            ->when($status === 'completed', fn ($q) => $q->where(fn ($sub) => $sub->whereNotNull('completed_at')->orWhere('status', 'completed')))
            ->when($status === 'pending', fn ($q) => $q->where('status', 'pending'))
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('article', fn ($articleQuery) => $articleQuery->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
            })
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest();

        $perPage = max(5, min(50, $request->integer('per_page', 20)));
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data'         => collect($paginator->items())->map(fn (SubEditorAssignment $a) => $this->subEditorAssignmentListPayload($a))->values(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ]);
    }

    public function myReviewerAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $observedUser = DeskObserverController::resolveObservedUser($request, 'reviewer');
        $deskUser = $observedUser ?: $user;

        if (!$observedUser && !$this->isGlobal($user) && !$user->hasRole('reviewer')) {
            return response()->json(['message' => 'Forbidden. Reviewer role required.'], 403);
        }

        $status = $request->query('status');
        $search = trim((string) $request->query('search'));

        $query = ReviewerAssignment::query()
            ->with([
                'article:id,magazine_id,title,slug,status,created_at,updated_at',
                'article.magazine:id,title,slug',
            ])
            ->when($observedUser || !$this->isGlobal($user), fn ($q) => $q->where('reviewer_id', $deskUser->id))
            ->where(fn ($q) => $q->whereNotNull('accepted_at')->orWhereNull('invite_token_hash'))
            ->when($status === 'active', fn ($q) => $q->whereNull('completed_at')->where('status', '!=', 'completed'))
            ->when($status === 'completed', fn ($q) => $q->where(fn ($sub) => $sub->whereNotNull('completed_at')->orWhere('status', 'completed')))
            ->when($status === 'pending', fn ($q) => $q->where('status', 'pending'))
            ->when($status === 'accepted', fn ($q) => $q->where('status', 'accepted'))
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('article', fn ($articleQuery) => $articleQuery->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
            })
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest();

        $perPage = max(5, min(50, $request->integer('per_page', 20)));
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data'         => collect($paginator->items())->map(fn (ReviewerAssignment $a) => $this->reviewerAssignmentListPayload($a))->values(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ]);
    }

    public function myProductionAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role');

        if ($role && !in_array($role, ['copy_editor'], true)) {
            return response()->json(['message' => 'Invalid production role.'], 422);
        }

        $status = $request->query('status');
        if ($status && !in_array($status, ['active', 'completed', 'pending'], true)) {
            return response()->json(['message' => 'Invalid assignment status.'], 422);
        }

        $observerRole = $role ?: ['copy_editor'];
        $observedUser = DeskObserverController::resolveObservedUser($request, $observerRole);
        $deskUser = $observedUser ?: $user;
        $allowedRole = $role ?: null;
        if ($observedUser) {
            $allowedRole = $role ?: 'copy_editor';
        } elseif (!$this->isGlobal($user)) {
            if (!$deskUser->hasRole('copy_editor')) {
                return response()->json(['message' => 'Forbidden. Production role required.'], 403);
            }
            $allowedRole = 'copy_editor';
            if ($role && $role !== $allowedRole) {
                return response()->json(['message' => 'Forbidden. Production role required.'], 403);
            }
        }

        $query = ProductionAssignment::query()
            ->with(['article:id,magazine_id,title,status,created_at,updated_at', 'article.magazine:id,title,slug'])
            ->when($observedUser || !$this->isGlobal($user), fn ($q) => $q->where('user_id', $deskUser->id))
            ->when($allowedRole, fn ($q) => $q->where('role', $allowedRole))
            ->when($status === 'active', fn ($q) => $q->whereNull('completed_at')->where('status', '!=', 'completed'))
            ->when($status === 'completed', fn ($q) => $q->where(function ($query) {
                $query->whereNotNull('completed_at')->orWhere('status', 'completed');
            }))
            ->when($status === 'pending', fn ($q) => $q->where('status', 'pending'))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at');

        $perPage = max(5, min(50, $request->integer('per_page', 15)));
        $assignments = $query->paginate($perPage);
        return response()->json([
            'data'         => collect($assignments->items())->map(fn (ProductionAssignment $a) => $this->productionAssignmentListPayload($a))->values(),
            'current_page' => $assignments->currentPage(),
            'last_page'    => $assignments->lastPage(),
            'total'        => $assignments->total(),
            'per_page'     => $assignments->perPage(),
        ]);
    }

    public function publisherDashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $observedUser = DeskObserverController::resolveObservedUser($request, 'publisher');
        $deskUser = $observedUser ?: $user;

        if (!$observedUser && !$this->isGlobal($user) && !$user->hasRole('publisher')) {
            return response()->json(['message' => 'Forbidden. Publisher role required.'], 403);
        }

        $magazineIds = ($this->isGlobal($user) && !$observedUser) ? null : $this->assignedMagazineIds($deskUser, ['publisher']);

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
        $this->rejectObserverMutation($request);
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor']);
        $oldStatus = $article->status;

        DB::transaction(function () use ($request, $article, $oldStatus) {
            $nextStatus = $request->decision === 'reject'
                ? ArticleStatus::REJECTED
                : ArticleStatus::UNDER_REVIEW;

            $article->update([
                'status' => $nextStatus,
                'screened_at' => now(),
                'screened_by' => $request->user()->id,
                'rejection_reason' => $request->decision === 'reject' ? $request->comments : null,
            ]);

            $this->audit($article, $request->user()->id, 'article.screened', $oldStatus, $nextStatus, $request->validated());
        });

        return response()->json([
            'message' => 'Article screening recorded.',
            'article' => $this->workflowArticlePayload($article->fresh(['magazine:id,title,slug', 'issue', 'files.uploader:id,name']), $request->user()),
        ]);
    }

    public function assignSubEditor(AssignSubEditorRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor']);
        $oldStatus = $article->status;

        $subEditor = User::findOrFail($request->sub_editor_id);
        
        // 1. Must not be an orphan
        if ($subEditor->assignedEditors()->count() === 0) {
            return response()->json([
                'message' => 'The selected Sub Editor has no Editor assignments and cannot be assigned to workflows.'
            ], 422);
        }

        // 2. Editor must be linked to the Sub-Editor, unless global
        $user = $request->user();
        if (!$this->isGlobal($user)) {
            $isLinked = $user->assignedSubEditors()->where('sub_editor_id', $subEditor->id)->exists();
            if (!$isLinked) {
                return response()->json([
                    'message' => 'You can only assign Sub Editors linked to your desk.'
                ], 422);
            }
        }

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

        $assignment->load('subEditor:id,name');
        event(new ArticleWorkflowEventOccurred($article->fresh(), 'sub_editor.assigned', $request->user(), [
            'sub_editor' => $assignment->subEditor,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
        ]));

        return response()->json([
            'message' => 'Sub editor assigned.',
            'assignment' => $this->minimalAssignmentPayload($assignment, $request->user()),
            'article' => $this->workflowArticlePayload($article->fresh(['magazine:id,title,slug', 'issue']), $request->user()),
        ], 201);
    }

    public function assignReviewer(AssignReviewerRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'sub_editor']);
        $oldStatus = $article->status;
        $article->loadMissing(['articleAuthors', 'reviewerPreferences']);

        $preference = null;
        if ($request->filled('suggested_preference_id')) {
            $preference = ArticleReviewerPreference::where('article_id', $article->id)
                ->where('type', ArticleReviewerPreference::SUGGESTED)
                ->findOrFail($request->integer('suggested_preference_id'));
        }

        $reviewer = $request->filled('reviewer_id') ? User::findOrFail($request->integer('reviewer_id')) : null;
        $inviteeName = $reviewer?->name ?: ($preference?->name ?: $request->input('name'));
        $inviteeEmail = strtolower(trim((string) ($reviewer?->email ?: ($preference?->email ?: $request->input('email')))));
        $this->assertReviewerInviteAllowed($article, $inviteeEmail);

        $existingReviewer = User::whereRaw('LOWER(email) = ?', [$inviteeEmail])->first();
        if ($existingReviewer && $existingReviewer->hasRole('reviewer')) {
            $reviewer = $existingReviewer;
        }

        $duplicate = ReviewerAssignment::query()
            ->where('article_id', $article->id)
            ->where(function ($query) use ($reviewer, $inviteeEmail) {
                if ($reviewer) {
                    $query->where('reviewer_id', $reviewer->id);
                }
                $query->orWhereRaw('LOWER(invitee_email) = ?', [$inviteeEmail]);
            })
            ->whereNull('declined_at')
            ->first();

        if ($duplicate) {
            return response()->json(['message' => 'This reviewer has already been invited or assigned for this article.'], 422);
        }

        $rawToken = Str::random(48);

        $assignment = DB::transaction(function () use ($request, $article, $oldStatus, $reviewer, $inviteeName, $inviteeEmail, $rawToken) {
            $assignment = ReviewerAssignment::create([
                    'article_id' => $article->id,
                    'reviewer_id' => $reviewer?->id,
                    'invitee_name' => $inviteeName,
                    'invitee_email' => $inviteeEmail,
                    'invite_token_hash' => hash('sha256', $rawToken),
                    'invited_at' => now(),
                    'invite_expires_at' => now()->addDays(21),
                    'sub_editor_assignment_id' => $request->sub_editor_assignment_id,
                    'assigned_by' => $request->user()->id,
                    'status' => 'pending',
                    'due_date' => $request->due_date,
                    'accepted_at' => null,
                    'completed_at' => null,
                ]);

            $article->update(['status' => ArticleStatus::REVIEWER_ASSIGNED]);
            $this->audit($article, $request->user()->id, 'reviewer.assigned', $oldStatus, ArticleStatus::REVIEWER_ASSIGNED, [
                'reviewer_id' => $reviewer?->id,
                'invitee_email' => $inviteeEmail,
                'due_date' => $request->due_date,
            ]);

            return $assignment;
        });

        $assignment->load('reviewer:id,name,email');
        event(new ArticleWorkflowEventOccurred($article->fresh(), 'reviewer.assigned', $request->user(), [
            'reviewer' => $assignment->reviewer,
            'invitee_name' => $assignment->invitee_name,
            'invitee_email' => $assignment->invitee_email,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::REVIEWER_ASSIGNED,
        ]));

        $this->sendReviewerInvitation($assignment, $rawToken);

        return response()->json([
            'message' => 'Reviewer invitation sent.',
            'assignment' => $this->minimalAssignmentPayload($assignment, $request->user()),
            'article' => $this->workflowArticlePayload($article->fresh(['magazine:id,title,slug', 'issue']), $request->user()),
        ], 201);
    }

    public function submitSubEditorRecommendation(SubmitSubEditorRecommendationRequest $request, int $assignmentId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $assignment = SubEditorAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (!$this->isGlobal($user) && (int) $assignment->sub_editor_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Sub editor assignment required.'], 403);
        }

        $oldStatus = $assignment->article->status;

        $storedFile = null;

        DB::transaction(function () use ($request, $assignment, $oldStatus, &$storedFile) {
            if ($request->hasFile('annotated_manuscript')) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Raw browser uploads are disabled for workflow files. Use the direct S3 upload-session flow.',
                ], 410));
            }

            if ($request->filled('annotated_manuscript_upload_id')) {
                $upload = app(CleanUploadResolver::class)->resolveOwned($request->user(), $request->annotated_manuscript_upload_id, 'article_annotated_manuscript');
                $storedFile = app(ArticleFileController::class)->createCleanDirectUploadFile($assignment->article, $upload, config('media_uploads.purposes.article_annotated_manuscript'), [
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
            'assignment' => $this->minimalAssignmentPayload($assignment->fresh(['article.magazine:id,title,slug']), $request->user()),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function acceptReviewerAssignment(Request $request, int $assignmentId): JsonResponse
    {
        $this->rejectObserverMutation($request);
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
            $this->ensureQuestionnaireInstance($assignment->fresh());

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
            'assignment' => $this->minimalAssignmentPayload($assignment->fresh(['article.magazine:id,title,slug']), $request->user()),
            'article' => $this->workflowArticlePayload($assignment->article->fresh(['magazine:id,title,slug', 'issue']), $request->user()),
        ]);
    }

    public function acceptReviewerInvitation(Request $request, int $assignmentId): JsonResponse
    {
        $validated = $request->validate(['token' => 'required|string']);
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $this->assertValidInvitationToken($assignment, $validated['token']);

        $user = $this->reviewerUserForInvitation($assignment);
        $oldStatus = $assignment->article->status;

        DB::transaction(function () use ($assignment, $user, $oldStatus) {
            $assignment->update([
                'reviewer_id' => $user->id,
                'status' => 'accepted',
                'accepted_at' => now(),
                'invite_token_hash' => null,
            ]);
            $assignment->article->update(['status' => ArticleStatus::REVIEW_IN_PROGRESS]);
            $this->ensureQuestionnaireInstance($assignment->fresh());
            $this->audit($assignment->article, $user->id, 'review.accepted', $oldStatus, ArticleStatus::REVIEW_IN_PROGRESS, [
                'reviewer_assignment_id' => $assignment->id,
            ]);
        });

        event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.accepted', $user, [
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
        ]));

        $this->sendReviewerAccessEmail($assignment->fresh(['reviewer']), $user);

        return response()->json(['message' => 'Review invitation accepted. You can now sign in to access the reviewer desk.']);
    }

    /** Public, token-gated context for an invitation. It intentionally excludes files and workflow internals. */
    public function showReviewerInvitation(Request $request, int $assignmentId): JsonResponse
    {
        $validated = $request->validate(['token' => 'required|string']);
        $assignment = ReviewerAssignment::with(['article.magazine:id,title'])->findOrFail($assignmentId);
        $this->assertValidInvitationToken($assignment, $validated['token']);
        $article = $assignment->article;

        return response()->json(['invitation' => [
            'id' => $assignment->id,
            'status' => $this->reviewerInvitationState($assignment),
            'reviewer_name' => $assignment->invitee_name,
            'article' => [
                'title' => $article->title,
                'abstract' => $article->abstract,
                'article_type' => $article->article_type,
                'article_category' => $article->article_category,
                'magazine' => $article->magazine?->title,
            ],
        ]]);
    }

    public function declineReviewerInvitation(Request $request, int $assignmentId): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'decline_reason' => 'nullable|string|max:2000',
        ]);
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $this->assertValidInvitationToken($assignment, $validated['token']);

        $assignment->update([
            'status' => 'declined',
            'declined_at' => now(),
            'decline_reason' => $validated['decline_reason'] ?? null,
            'invite_token_hash' => null,
        ]);
        $this->audit($assignment->article, null, 'review.declined', $assignment->article->status, $assignment->article->status, [
            'reviewer_assignment_id' => $assignment->id,
        ]);
        event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.declined', null, ['from_status' => $assignment->article->status, 'to_status' => $assignment->article->status]));

        return response()->json(['message' => 'Review invitation declined.']);
    }

    public function submitReview(SubmitReviewRequest $request, int $assignmentId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (!$this->isGlobal($user) && (int) $assignment->reviewer_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Reviewer assignment required.'], 403);
        }

        if (!$assignment->accepted_at && $assignment->invite_token_hash) {
            if ((int) $assignment->reviewer_id !== (int) $user->id) {
                return response()->json(['message' => 'Accept the review invitation before submitting a review.'], 422);
            }

            $assignment->forceFill([
                'accepted_at' => now(),
                'invite_token_hash' => null,
                'invite_expires_at' => null,
                'status' => 'review_in_progress',
            ])->save();
            $this->ensureQuestionnaireInstance($assignment->fresh('article'));
        }

        $questionnaireError = $this->validateQuestionnaireResponses($assignment, $request->input('questionnaire_responses', []));
        if ($questionnaireError) {
            return response()->json(['message' => $questionnaireError], 422);
        }

        $oldStatus = $assignment->article->status;

        $storedFile = null;

        DB::transaction(function () use ($request, $assignment, $oldStatus, &$storedFile) {
            if ($request->hasFile('reviewed_manuscript')) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Raw browser uploads are disabled for workflow files. Use the direct S3 upload-session flow.',
                ], 410));
            }

            if ($request->filled('reviewed_manuscript_upload_id')) {
                $upload = app(CleanUploadResolver::class)->resolveOwned($request->user(), $request->reviewed_manuscript_upload_id, 'article_reviewed_manuscript');
                $storedFile = app(ArticleFileController::class)->createCleanDirectUploadFile($assignment->article, $upload, config('media_uploads.purposes.article_reviewed_manuscript'), [
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
            $this->persistQuestionnaireResponses($assignment->fresh(), $request->input('questionnaire_responses', []));

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
            'assignment' => $this->minimalAssignmentPayload($assignment->fresh(['article.magazine:id,title,slug']), $request->user()),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function reopenReviewer(Request $request, int $assignmentId): JsonResponse
    {
        $this->rejectObserverMutation($request);
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
            'assignment' => $this->minimalAssignmentPayload($assignment->fresh(['article.magazine:id,title,slug']), $request->user()),
        ]);
    }

    public function finalDecision(FinalDecisionRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
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
            'decision' => $this->editorialDecisionPayload($decision->fresh(['decider:id,name']), true),
            'article' => $this->workflowArticlePayload($article->fresh(['magazine:id,title,slug', 'issue']), $request->user()),
        ], 201);
    }

    public function authorFinalReview(Request $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'sub_editor', 'reviewer'], false);
        $user = $request->user();
        $oldStatus = ArticleStatus::normalize($article->status);

        if ($oldStatus !== ArticleStatus::ACCEPTED) {
            return response()->json(['message' => 'Only accepted manuscripts can be approved for final production review.'], 422);
        }

        if ($article->author_final_approved_at) {
            return response()->json(['message' => 'This manuscript has already been approved for final review.'], 422);
        }

        if (!$this->canApproveAuthorFinalReview($user, $article)) {
            return response()->json(['message' => 'Only the manuscript owner or corresponding author may approve final review.'], 403);
        }

        DB::transaction(function () use ($article, $user, $oldStatus) {
            $article->update([
                'status' => ArticleStatus::COPY_EDITING,
                'author_final_approved_at' => now(),
                'author_final_approved_by' => $user->id,
            ]);

            $this->audit($article, $user->id, 'author.final_review_approved', $oldStatus, ArticleStatus::COPY_EDITING, [
                'approved_by' => $user->id,
            ]);
        });

        $fresh = $article->fresh(['magazine:id,title,slug', 'issue', 'articleAuthors', 'files.uploader:id,name']);
        event(new ArticleWorkflowEventOccurred($fresh, 'author.final_review_approved', $user, [
            'from_status' => $oldStatus,
            'to_status' => ArticleStatus::COPY_EDITING,
        ]));

        return response()->json([
            'message' => 'Author final review approved.',
            'article' => $this->workflowArticlePayload($fresh, $user),
        ]);
    }

    public function assignProduction(ProductionAssignmentRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher']);
        $oldStatus = $article->status;
        $nextStatus = ArticleStatus::COPY_EDITING;

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

        $assignment->load('user:id,name');
        event(new ArticleWorkflowEventOccurred($article->fresh(), 'production.assigned', $request->user(), [
            'assignee' => $assignment->user,
            'assignment_id' => $assignment->id,
            'from_status' => $oldStatus,
            'to_status' => $nextStatus,
        ]));

        return response()->json([
            'message' => 'Production assignment created.',
            'assignment' => $this->minimalAssignmentPayload($assignment, $request->user()),
            'article' => $this->workflowArticlePayload($article->fresh(['magazine:id,title,slug', 'issue']), $request->user()),
        ], 201);
    }

    public function completeProduction(Request $request, int $assignmentId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $request->validate([
            'production_file' => 'nullable|file|mimes:pdf,doc,docx|max:25600',
            'production_file_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
        ]);

        $assignment = ProductionAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (!$this->isGlobal($user) && (int) $assignment->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Production assignment required.'], 403);
        }

        if (!$this->isGlobal($user)
            && (!$user->hasRole('copy_editor') || $assignment->role !== 'copy_editor')) {
            return response()->json(['message' => 'Forbidden. Production assignment role mismatch.'], 403);
        }

        if ($assignment->role !== 'copy_editor') {
            return response()->json(['message' => 'Proofreader assignments are inactive.'], 403);
        }

        $storedFile = null;
        $oldStatus = $assignment->article->status;

        if ($request->hasFile('production_file')) {
            return response()->json([
                'message' => 'Raw browser uploads are disabled for workflow files. Use the direct S3 upload-session flow.',
            ], 410);
        }

        if ($request->filled('production_file_upload_id')) {
            $purpose = 'article_production_file';
            $upload = app(CleanUploadResolver::class)->resolveOwned($user, $request->production_file_upload_id, $purpose);
            $storedFile = app(ArticleFileController::class)->createCleanDirectUploadFile(
                $assignment->article,
                $upload,
                config('media_uploads.purposes.' . $purpose),
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
            'assignment' => $this->minimalAssignmentPayload($assignment->fresh(['article.magazine:id,title,slug']), $user),
            'article' => $this->workflowArticlePayload($assignment->article->fresh(['magazine:id,title,slug', 'issue']), $user),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function issues(Request $request): JsonResponse
    {
        if (!$this->canUseIssueManager($request->user())) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        $query = MagazineIssue::with('magazine:id,title,slug')
            ->withCount('articles')
            ->orderByDesc('published_at')
            ->orderByDesc('issue_year')
            ->orderByRaw($this->issueMonthOrderSql() . ' DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!$this->isGlobal($request->user())) {
            $query->whereIn('magazine_id', $this->issueManagerMagazineIds($request->user()));
        }

        if ($request->filled('magazine_id')) {
            $query->where('magazine_id', $request->integer('magazine_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function issueMagazines(Request $request): JsonResponse
    {
        if (!$this->canUseIssueManager($request->user())) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        $query = Magazine::query()->select(['id', 'title', 'slug'])->orderBy('title');

        if (!$this->isGlobal($request->user())) {
            $query->whereIn('id', $this->issueManagerMagazineIds($request->user()));
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
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        return response()->json(['issue' => $this->issuePayload($issue)]);
    }

    public function storeIssue(MagazineIssueRequest $request): JsonResponse
    {
        if (!$this->canUseIssueManager($request->user()) || (!$request->user()->hasRole('super_admin') && !$this->canManageIssueMagazine($request->user(), (int) $request->magazine_id))) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        if ($this->requestChangesIssuePublicationState($request)
            && !$this->canPublishIssueMagazine($request->user(), (int) $request->magazine_id)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required for publication state changes.'], 403);
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
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        if ((int) $issue->magazine_id !== (int) $request->integer('magazine_id')
            && !$request->user()->hasRole('super_admin')
            && !$this->canManageIssueMagazine($request->user(), $request->integer('magazine_id'))) {
            return response()->json(['message' => 'Forbidden. Issue manager assignment required for target magazine.'], 403);
        }

        if ($this->requestChangesIssuePublicationState($request)
            && !$this->canPublishIssueMagazine($request->user(), $request->integer('magazine_id'))) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required for publication state changes.'], 403);
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

        if (!$this->canPublishIssue($request->user(), $issue)) {
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

        if (!$this->canPublishIssue($request->user(), $issue)) {
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
        if (!$this->canUseIssueManager($request->user())) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        $request->validate([
            'magazine_id' => 'nullable|exists:magazines,id',
            'issue_id' => 'nullable|exists:magazine_issues,id',
        ]);

        $magazineId = $request->integer('magazine_id') ?: null;
        if ($request->filled('issue_id')) {
            $issue = MagazineIssue::findOrFail($request->integer('issue_id'));
            if (!$this->canManageIssue($request->user(), $issue)) {
                return response()->json(['message' => 'Forbidden. Issue manager assignment required.'], 403);
            }
            $magazineId = $issue->magazine_id;
        } elseif (!$this->isGlobal($request->user()) && $magazineId && !$this->canManageIssueMagazine($request->user(), $magazineId)) {
            return response()->json(['message' => 'Forbidden. Issue manager assignment required.'], 403);
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
            $query->whereIn('magazine_id', $this->issueManagerMagazineIds($request->user()));
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (Article $article) => $this->publicationArticlePayload($article))->values()]);
    }

    public function publish(PublishArticleRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
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
                throw new HttpResponseException(response()->json([
                    'message' => 'Raw browser uploads are disabled for published PDFs. Use the direct S3 upload-session flow.',
                ], 410));
            }

            if ($request->filled('publication_pdf_upload_id')) {
                $upload = app(CleanUploadResolver::class)->resolveOwned($request->user(), $request->publication_pdf_upload_id, 'article_published_pdf');
                $storedFile = app(ArticleFileController::class)->createCleanDirectUploadFile($article, $upload, config('media_uploads.purposes.article_published_pdf'));
                $article->pdf_path = $storedFile->file_path;
            }

            $article->update([
                'status' => ArticleStatus::PUBLISHED,
                'magazine_issue_id' => $request->magazine_issue_id,
                'doi' => $request->doi,
                'article_type' => $request->input('article_type', $article->article_type),
                'article_category' => $request->input('article_category', $article->article_category),
                'open_access_label' => $request->input('open_access_label'),
                'is_peer_reviewed' => $request->boolean('is_peer_reviewed', true),
                'academic_editor' => $request->input('academic_editor'),
                'received_at' => $request->input('received_at'),
                'accepted_at' => $request->input('accepted_at'),
                'license_statement' => $request->input('license_statement'),
                'data_availability_statement' => $request->input('data_availability_statement', $article->data_availability_statement),
                'funding_statement' => $request->input('funding_statement', $article->funding_statement),
                'competing_interests_statement' => $request->input('competing_interests_statement'),
                'abbreviations' => $request->input('abbreviations'),
                'citation_text' => $request->input('citation_text'),
                'published_year' => $request->published_year,
                'published_month' => $request->published_month,
                'page_start' => $request->page_start,
                'page_end' => $request->page_end,
                'published_at' => $request->input('published_at') ?: now(),
            ]);
            $this->persistPublicationSections($article, $request->input('publication_sections', []), $request->user());

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
            'article' => $this->publicationArticlePayload($article->fresh(['magazine', 'issue', 'articleAuthors', 'publicationSections'])),
            'citation' => [
                'format' => 'APA',
                'text' => $this->citationService->apa($article->fresh(['magazine', 'issue', 'articleAuthors'])),
            ],
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function postPublication(PostPublicationActionRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
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
            'action' => $this->postPublicationActionPayload($action->fresh(['performer:id,name'])),
            'article' => $this->publicationArticlePayload($article->fresh(['magazine:id,title,slug', 'issue', 'articleAuthors'])),
        ], 201);
    }

    public function publicationSectionImage(Request $request, int $sectionId)
    {
        $section = ArticlePublicationSection::with('article')->find($sectionId);
        if (!$section || !$section->article || !$section->media_upload_session_id) {
            return response()->json(['message' => 'The requested image is not available.'], 404);
        }

        if (ArticleStatus::normalize($section->article->status) !== ArticleStatus::PUBLISHED) {
            return response()->json(['message' => 'The requested image is not available.'], 404);
        }

        $upload = MediaUploadSession::whereKey($section->media_upload_session_id)
            ->where('purpose', 'publication_section_image')
            ->where('status', MediaUploadSession::STATUS_CLEAN)
            ->first();

        if (!$upload || !$upload->s3_clean_key) {
            return response()->json(['message' => 'The requested image is not available.'], 404);
        }

        return app(MediaStorageService::class)->downloadResponse(
            $upload->s3_clean_key,
            $upload->safe_display_filename ?: $upload->original_filename ?: 'section-image',
            $upload->detected_mime_type ?: $upload->declared_mime_type ?: 'application/octet-stream',
            'inline'
        );
    }

    public function auditLogs(Request $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor'], false);

        return response()->json([
            'data' => $article->auditLogs()->with('actor:id,name')->latest()->paginate($request->integer('per_page', 25))->through(fn ($log) => [
                'id' => $log->id,
                'article_id' => $log->article_id,
                'event' => $log->event,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'created_at' => $log->created_at,
            ]),
        ]);
    }

    public function questionnaire(Request $request): JsonResponse
    {
        if (!$this->isGlobal($request->user())) {
            return response()->json(['message' => 'Forbidden. Super Admin access required.'], 403);
        }

        $questionnaire = \App\Models\ReviewQuestionnaire::with(['versions.questions.options'])
            ->latest()
            ->first();

        return response()->json(['questionnaire' => $questionnaire ? $this->questionnairePayload($questionnaire) : null]);
    }

    public function storeQuestionnaire(Request $request): JsonResponse
    {
        if (!$this->isGlobal($request->user())) {
            return response()->json(['message' => 'Forbidden. Super Admin access required.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'questions' => 'required|array|min:1',
            'questions.*.prompt' => 'required|string|max:1000',
            'questions.*.response_type' => 'required|in:radio,checkbox,dropdown,single_line,textarea',
            'questions.*.is_required' => 'nullable|boolean',
            'questions.*.options' => 'nullable|array',
            'questions.*.options.*' => 'nullable|string|max:255',
        ]);

        $questionnaire = DB::transaction(function () use ($request, $validated) {
            $questionnaire = \App\Models\ReviewQuestionnaire::firstOrCreate(
                ['name' => $validated['name']],
                ['created_by' => $request->user()->id]
            );

            \App\Models\ReviewQuestionnaireVersion::where('review_questionnaire_id', $questionnaire->id)->update(['is_active' => false]);
            \App\Models\ReviewQuestionnaire::query()->update(['is_active' => false]);
            $versionNumber = ((int) $questionnaire->versions()->max('version_number')) + 1;
            $version = \App\Models\ReviewQuestionnaireVersion::create([
                'review_questionnaire_id' => $questionnaire->id,
                'version_number' => $versionNumber,
                'is_active' => true,
                'published_at' => now(),
            ]);

            foreach ($validated['questions'] as $index => $questionData) {
                $question = \App\Models\ReviewQuestion::create([
                    'review_questionnaire_version_id' => $version->id,
                    'prompt' => $questionData['prompt'],
                    'response_type' => $questionData['response_type'],
                    'is_required' => (bool) ($questionData['is_required'] ?? false),
                    'sort_order' => $index + 1,
                ]);

                if (in_array($question->response_type, ['radio', 'checkbox', 'dropdown'], true)) {
                    foreach (array_values(array_filter($questionData['options'] ?? [])) as $optionIndex => $optionLabel) {
                        \App\Models\ReviewQuestionOption::create([
                            'review_question_id' => $question->id,
                            'label' => $optionLabel,
                            'value' => Str::slug($optionLabel) ?: 'option-' . ($optionIndex + 1),
                            'sort_order' => $optionIndex + 1,
                        ]);
                    }
                }
            }

            $questionnaire->update(['is_active' => true]);

            return $questionnaire->fresh(['versions.questions.options']);
        });

        return response()->json(['questionnaire' => $this->questionnairePayload($questionnaire)], 201);
    }

    private function questionnairePayload(\App\Models\ReviewQuestionnaire $questionnaire): array
    {
        $activeVersion = $questionnaire->versions->sortByDesc('version_number')->firstWhere('is_active', true)
            ?: $questionnaire->versions->sortByDesc('version_number')->first();

        return [
            'id' => $questionnaire->id,
            'name' => $questionnaire->name,
            'is_active' => $questionnaire->is_active,
            'active_version' => $activeVersion ? [
                'id' => $activeVersion->id,
                'version_number' => $activeVersion->version_number,
                'questions' => $activeVersion->questions->map(fn ($question) => [
                    'id' => $question->id,
                    'prompt' => $question->prompt,
                    'response_type' => $question->response_type,
                    'is_required' => $question->is_required,
                    'options' => $question->options->pluck('label')->values(),
                ])->values(),
            ] : null,
        ];
    }

    private function issueData(MagazineIssueRequest $request, ?MagazineIssue $existing = null): array
    {
        $validated = $request->validated();
        $status = $validated['status'] ?? ($request->boolean('is_published') ? 'published' : ($existing?->status ?? 'draft'));
        $isPublished = $status === 'published' || (bool) ($validated['is_published'] ?? false);
        $coverImage = $existing?->cover_image;

        if ($request->hasFile('cover_image')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Raw browser uploads are disabled for issue covers. Use the direct S3 upload-session flow.',
            ], 410));
        }

        if (!empty($validated['cover_image_upload_id'])) {
            if ($coverImage) {
                app(MediaStorageService::class)->delete($coverImage);
            }
            $coverImage = app(CleanUploadResolver::class)->cleanKey($request->user(), $validated['cover_image_upload_id'], 'issue_cover');
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

    private function persistPublicationSections(Article $article, array $sections, User $user): void
    {
        $keptIds = [];

        foreach (array_values($sections) as $index => $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $html = $this->sanitizeRichText((string) ($section['content_html'] ?? ''));
            if ($title === '' && $html === '') {
                continue;
            }

            $key = Str::slug((string) ($section['section_key'] ?? '')) ?: Str::slug($title);
            $key = $key ?: 'section-' . ($index + 1);
            $key = str_replace('-', '_', Str::limit($key, 120, ''));
            $mediaUploadId = $section['media_upload_session_id'] ?? null;
            if ($mediaUploadId) {
                app(CleanUploadResolver::class)->resolveOwned($user, $mediaUploadId, 'publication_section_image');
            }

            $record = ArticlePublicationSection::updateOrCreate(
                ['article_id' => $article->id, 'section_key' => $key],
                [
                    'title' => $title ?: Str::headline(str_replace('_', ' ', $key)),
                    'content_html' => $html,
                    'content_text' => trim(html_entity_decode(strip_tags($html))),
                    'sort_order' => (int) ($section['sort_order'] ?? ($index + 1)),
                    'media_upload_session_id' => $mediaUploadId ?: ($section['existing_media_upload_session_id'] ?? null),
                ]
            );
            $keptIds[] = $record->id;
        }

        $article->publicationSections()
            ->when(count($keptIds) > 0, fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
    }

    private function sanitizeRichText(string $html): string
    {
        $allowed = '<p><br><strong><b><em><i><u><ol><ul><li><blockquote><a><h2><h3><h4><table><thead><tbody><tr><th><td><sup><sub>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', 'href="#"', $clean) ?? '';

        return trim($clean);
    }

    private function assertReviewerInviteAllowed(Article $article, string $email): void
    {
        if (!$email) {
            throw new HttpResponseException(response()->json(['message' => 'Reviewer email is required.'], 422));
        }

        $authorEmails = $article->articleAuthors
            ->pluck('co_author_email')
            ->push($article->user?->email)
            ->filter()
            ->map(fn ($value) => strtolower((string) $value));

        if ($authorEmails->contains($email)) {
            throw new HttpResponseException(response()->json(['message' => 'Article authors and co-authors cannot be assigned as reviewers.'], 422));
        }

        $opposed = $article->reviewerPreferences
            ->where('type', ArticleReviewerPreference::OPPOSED)
            ->pluck('email')
            ->map(fn ($value) => strtolower((string) $value));

        if ($opposed->contains($email)) {
            throw new HttpResponseException(response()->json(['message' => 'This reviewer is listed as an opposing reviewer and cannot be assigned.'], 422));
        }
    }

    private function sendReviewerInvitation(ReviewerAssignment $assignment, string $rawToken): void
    {
        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/');
        $article = $assignment->article()->with(['magazine:id,title', 'articleAuthors'])->first();
        $author = $article?->articleAuthors?->firstWhere('is_corresponding', true)?->co_author_name
            ?? $article?->user?->name
            ?? 'the submitting author';
        app(\App\Services\NotificationService::class)->send(
            $assignment->invitee_email,
            'Review Invitation: ' . ($article?->title ?? 'Article Review') . ' — ' . ($article?->magazine?->title ?? 'ScholarlyNest'),
            $assignment->invitee_name ? 'Dear ' . $assignment->invitee_name . ',' : 'Hello,',
            [
                'You have been invited to review the article "' . ($article?->title ?? 'Untitled Article') . '" for ' . ($article?->magazine?->title ?? 'ScholarlyNest') . '.',
                'Article Details: Title: ' . ($article?->title ?? 'Untitled Article') . '. Magazine: ' . ($article?->magazine?->title ?? 'ScholarlyNest') . '. Tracking Code: ' . ($article?->tracking_code ?? 'Not assigned') . '.',
                'Article Type: ' . ($article?->article_type ?: 'Not specified') . '. Category: ' . ($article?->article_category ?: 'Not specified') . '. Corresponding Author: ' . $author . '.',
                'Abstract: ' . strip_tags((string) ($article?->abstract ?? 'Not provided.')),
                'Next Action: Please accept or decline this review invitation using the secure link below. If accepted, permitted manuscript files become available in your reviewer dashboard; if declined, the editorial team is notified.',
                'Security Note: This invitation link is intended only for you. Do not forward it. Manuscript files, S3 URLs, questionnaire responses, and internal editorial notes are not included in this email.',
            ],
            [
                'text' => 'Open Review Invitation',
                'url' => "{$frontendUrl}/review-invitations/{$assignment->id}?token={$rawToken}",
            ],
            'default',
            $assignment->reviewer_id
        );
    }

    private function sendReviewerAccessEmail(ReviewerAssignment $assignment, User $user): void
    {
        if ($user->needs_password_reset) {
            $this->passwordSetupService->sendSetupLink($user);
            return;
        }

        app(\App\Services\NotificationService::class)->send(
            $user->email,
            'Reviewer Access Ready',
            'Dear ' . $user->name . ',',
            [
                'Your reviewer access is ready for the article "' . ($assignment->article?->title ?? 'Untitled Article') . '".',
                'You may sign in with your existing account.',
            ],
            [
                'text' => 'Open Reviewer Desk',
                'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/') . '/admin/reviewer',
            ],
            'default',
            $user->id
        );
    }

    private function assertValidInvitationToken(ReviewerAssignment $assignment, string $rawToken): void
    {
        if (
            !$assignment->invite_token_hash
            || !hash_equals($assignment->invite_token_hash, hash('sha256', $rawToken))
            || ($assignment->invite_expires_at && $assignment->invite_expires_at->isPast())
            || $assignment->declined_at
            || $assignment->completed_at
        ) {
            throw new HttpResponseException(response()->json(['message' => 'This review invitation is invalid or expired.'], 422));
        }
    }

    private function reviewerUserForInvitation(ReviewerAssignment $assignment): User
    {
        $email = strtolower((string) $assignment->invitee_email);
        $reviewerRole = Role::where('name', 'reviewer')->first();
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            if ($reviewerRole && !$user->hasRole('reviewer')) {
                $user->update(['role_id' => $reviewerRole->id]);
            }
            return $user;
        }

        $user = User::create([
            'name' => $assignment->invitee_name ?: $email,
            'email' => $email,
            'password' => null,
            'needs_password_reset' => true,
            'email_verified_at' => now(),
            'role_id' => $reviewerRole?->id,
        ]);
        $assignment->forceFill(['account_created_at' => now()])->saveQuietly();

        return $user;
    }

    private function ensureQuestionnaireInstance(ReviewerAssignment $assignment): ?ReviewQuestionnaireInstance
    {
        if ($assignment->questionnaire_instance_id) {
            return $assignment->questionnaireInstance;
        }

        $version = ReviewQuestionnaireVersion::query()
            ->where('is_active', true)
            ->with('questions.options')
            ->latest('published_at')
            ->latest('id')
            ->first();

        if (!$version) {
            return null;
        }

        $instance = ReviewQuestionnaireInstance::firstOrCreate(
            ['reviewer_assignment_id' => $assignment->id],
            [
                'article_id' => $assignment->article_id,
                'reviewer_id' => $assignment->reviewer_id,
                'review_questionnaire_version_id' => $version->id,
            ]
        );
        $assignment->update(['questionnaire_instance_id' => $instance->id]);

        return $instance;
    }

    private function validateQuestionnaireResponses(ReviewerAssignment $assignment, array $responses): ?string
    {
        $instance = $this->ensureQuestionnaireInstance($assignment);
        if (!$instance) {
            return null;
        }

        $instance->loadMissing('version.questions.options');
        $answers = collect($responses)->keyBy(fn ($row) => (int) ($row['question_id'] ?? 0));
        foreach ($instance->version->questions as $question) {
            if (!$question->is_required) {
                continue;
            }
            $answer = $answers->get($question->id)['answer'] ?? null;
            if ($answer === null || $answer === '' || (is_array($answer) && count(array_filter($answer, fn ($v) => $v !== null && $v !== '')) === 0)) {
                return 'Please answer all required reviewer questionnaire questions before submitting your review.';
            }
        }

        return null;
    }

    private function persistQuestionnaireResponses(ReviewerAssignment $assignment, array $responses): void
    {
        $instance = $this->ensureQuestionnaireInstance($assignment);
        if (!$instance) {
            return;
        }

        foreach ($responses as $row) {
            $questionId = (int) ($row['question_id'] ?? 0);
            if (!$questionId) {
                continue;
            }
            \App\Models\ReviewQuestionResponse::updateOrCreate(
                [
                    'review_questionnaire_instance_id' => $instance->id,
                    'review_question_id' => $questionId,
                ],
                ['answer' => $row['answer'] ?? null]
            );
        }
        $instance->update([
            'reviewer_id' => $assignment->reviewer_id,
            'submitted_at' => now(),
        ]);
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
            'cover_image_url' => $issue->cover_image_url,
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
        $article->loadMissing(['magazine:id,title,slug', 'issue:id,volume_number,issue_number,special_title,issue_month,issue_year', 'articleAuthors', 'publicationSections']);

        return [
            'id' => $article->id,
            'tracking_code' => $article->tracking_code,
            'magazine_id' => $article->magazine_id,
            'magazine_issue_id' => $article->magazine_issue_id,
            'magazine' => $article->magazine ? [
                'id' => $article->magazine->id,
                'title' => $article->magazine->title,
                'slug' => $article->magazine->slug,
            ] : null,
            'issue' => $article->issue ? [
                'id' => $article->issue->id,
                'volume_number' => $article->issue->volume_number,
                'issue_number' => $article->issue->issue_number,
                'special_title' => $article->issue->special_title,
                'issue_month' => $article->issue->issue_month,
                'issue_year' => $article->issue->issue_year,
            ] : null,
            'title' => $article->title,
            'subtitle' => $article->subtitle,
            'slug' => $article->slug,
            'abstract' => $article->abstract,
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
            'status' => $article->status,
            'published_year' => $article->published_year,
            'published_month' => $article->published_month,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'published_at' => $article->published_at,
            'has_pdf' => !empty($article->pdf_path),
            'pdf_url' => $article->pdf_path ? url("/api/articles/{$article->id}/download-pdf") : null,
            'featured_image_url' => $article->featured_image_url,
            'article_url' => $this->articleUrl($article),
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
            'citation' => [
                'format' => 'APA',
                'text' => $article->citation_text ?: $this->citationService->apa($article),
            ],
            'publication_sections' => $article->publicationSections
                ->map(fn ($section) => [
                    'id' => $section->id,
                    'section_key' => $section->section_key,
                    'title' => $section->title,
                    'content_html' => $section->content_html,
                    'content_text' => $section->content_text,
                    'sort_order' => $section->sort_order,
                    'has_image' => !empty($section->media_upload_session_id),
                    'image_url' => $section->media_upload_session_id ? url("/api/articles/publication-sections/{$section->id}/image") : null,
                ])
                ->sortBy('sort_order')
                ->values(),
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
        ];
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
        return $this->canUseIssueManager($user)
            && ($user->hasRole('super_admin') || $this->canManageIssueMagazine($user, $issue->magazine_id));
    }

    private function canPublishIssue($user, MagazineIssue $issue): bool
    {
        return $this->canManageIssue($user, $issue);
    }

    private function canPublishIssueMagazine($user, int $magazineId): bool
    {
        return $this->canUseIssueManager($user)
            && ($user->hasRole('super_admin') || $this->isAssignedToMagazine($user, $magazineId, ['publisher']));
    }

    private function canManageIssueMagazine($user, int $magazineId): bool
    {
        return $this->isAssignedToMagazine($user, $magazineId, ['publisher']);
    }

    private function issueManagerMagazineIds($user): array
    {
        return $this->assignedMagazineIds($user, ['publisher']);
    }

    private function canUseIssueManager($user): bool
    {
        return $user && ($user->hasRole('super_admin') || $user->hasRole('publisher'));
    }

    private function issueMonthOrderSql(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "CASE lower(issue_month) WHEN 'january' THEN 1 WHEN 'february' THEN 2 WHEN 'march' THEN 3 WHEN 'april' THEN 4 WHEN 'may' THEN 5 WHEN 'june' THEN 6 WHEN 'july' THEN 7 WHEN 'august' THEN 8 WHEN 'september' THEN 9 WHEN 'october' THEN 10 WHEN 'november' THEN 11 WHEN 'december' THEN 12 ELSE CAST(issue_month AS INTEGER) END",
            default => "CASE lower(issue_month) WHEN 'january' THEN 1 WHEN 'february' THEN 2 WHEN 'march' THEN 3 WHEN 'april' THEN 4 WHEN 'may' THEN 5 WHEN 'june' THEN 6 WHEN 'july' THEN 7 WHEN 'august' THEN 8 WHEN 'september' THEN 9 WHEN 'october' THEN 10 WHEN 'november' THEN 11 WHEN 'december' THEN 12 ELSE CAST(issue_month AS UNSIGNED) END",
        };
    }

    private function requestChangesIssuePublicationState(Request $request): bool
    {
        if ($request->has('is_published') || $request->has('published_at')) {
            return true;
        }

        return in_array($request->input('status'), ['published', 'unpublished'], true);
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

        if (in_array('copy_editor', $roles, true) && $this->hasProductionAssignment($user, $article, 'copy_editor')) {
            return $article;
        }

        if (in_array('publisher', $roles, true)
            && $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher'])
            && in_array(ArticleStatus::normalize($article->status), [
                ArticleStatus::ACCEPTED,
                ArticleStatus::COPY_EDITING,
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

    private function canApproveAuthorFinalReview($user, Article $article): bool
    {
        if (!$user) {
            return false;
        }

        if ((int) $article->user_id === (int) $user->id) {
            return true;
        }

        $article->loadMissing('articleAuthors');

        return $article->articleAuthors->contains(function ($author) use ($user) {
            if (!$author->is_corresponding) {
                return false;
            }

            return (int) $author->user_id === (int) $user->id
                || strtolower((string) $author->co_author_email) === strtolower((string) $user->email);
        });
    }

    private function reviewerInvitationState(ReviewerAssignment $assignment): string
    {
        if ($assignment->completed_at) {
            return 'completed';
        }

        if ($assignment->declined_at || $assignment->status === 'declined') {
            return 'declined';
        }

        if ($assignment->accepted_at || in_array($assignment->status, ['accepted', 'in_progress'], true)) {
            return 'accepted';
        }

        return 'invited';
    }

    private function assignmentRelations(): array
    {
        return [
            'article.issue',
            'article.magazine:id,title,slug',
            'article.articleAuthors',
            'article.assets',
            'article.publicationSections',
            'article.files.uploader:id,name',
            'article.subEditorAssignments.subEditor:id,name',
            'article.reviewerAssignments.reviewer:id,name',
            'article.editorialDecisions.decider:id,name',
            'article.productionAssignments.user:id,name',
            'article.postPublicationActions.performer:id,name',
            'article.auditLogs.actor:id,name',
            'article.versions.creator:id,name',
            'article.versions.files.uploader:id,name',
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
        $canViewEditorial = $this->canViewEditorialInternals($user, $article);
        $canViewReviewWorkflow = $canViewEditorial || $this->hasSubEditorAssignment($user, $article);
        $canViewPublication = $canViewEditorial || $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher']);
        $canViewProduction = $canViewPublication
            || $this->hasProductionAssignment($user, $article, 'copy_editor');

        $data = [
            'id' => $article->id,
            'tracking_code' => $article->tracking_code,
            'magazine_id' => $article->magazine_id,
            'magazine' => $article->magazine ? [
                'id' => $article->magazine->id,
                'title' => $article->magazine->title,
                'slug' => $article->magazine->slug,
            ] : null,
            'issue' => $article->issue ? [
                'id' => $article->issue->id,
                'volume_number' => $article->issue->volume_number,
                'issue_number' => $article->issue->issue_number,
                'special_title' => $article->issue->special_title,
                'issue_month' => $article->issue->issue_month,
                'issue_year' => $article->issue->issue_year,
            ] : null,
            'title' => $article->title,
            'subtitle' => $article->subtitle,
            'slug' => $article->slug,
            'abstract' => $article->abstract,
            'full_text' => $article->full_text,
            'status' => $article->status,
            'author_status' => ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::normalize($article->status)] ?? $article->status,
            'doi' => $article->doi,
            'published_at' => $article->published_at,
            'published_year' => $article->published_year,
            'published_month' => $article->published_month,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'has_pdf' => !empty($article->pdf_path),
            'files' => app(ArticleFileController::class)->filterVisibleFiles($user, $article->files ?? []),
            'assets' => collect($article->assets ?? [])
                ->filter(fn ($asset) => ($asset->scan_status ?? 'clean') === 'clean')
                ->map(fn ($asset) => [
                    'id' => $asset->id,
                    'asset_type' => $asset->asset_type ?: 'supplementary',
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
                    'media_upload_session_id' => $section->media_upload_session_id,
                    'has_image' => !empty($section->media_upload_session_id),
                    'image_url' => $section->media_upload_session_id ? url("/api/articles/publication-sections/{$section->id}/image") : null,
                ])
                ->sortBy('sort_order')
                ->values(),
            'versions' => $this->serializedVersions($article, $user),
            'article_authors' => $article->articleAuthors
                ->sortBy('author_order')
                ->map(fn ($author) => [
                    'id' => $author->id,
                    'user_id' => $author->user_id,
                    'co_author_name' => $author->co_author_name,
                    'co_author_email' => $canViewEditorial || (int) $article->user_id === (int) ($user?->id ?? 0) || $this->isArticleAuthorRecord($user, $article) ? $author->co_author_email : null,
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
            'author_final_approved_at' => $article->author_final_approved_at,
            'author_final_approved_by' => $article->author_final_approved_by,
            'can_author_final_review' => $this->canApproveAuthorFinalReview($user, $article)
                && ArticleStatus::normalize($article->status) === ArticleStatus::ACCEPTED
                && !$article->author_final_approved_at,
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
        ];

        if ($canViewEditorial || (int) $article->user_id === (int) ($user?->id ?? 0) || $this->isArticleAuthorRecord($user, $article)) {
            $data['change_summary'] = $article->change_summary;
            $data['revision_response'] = $article->revision_response;
            $data['rejection_reason'] = $article->rejection_reason;
        }

        $data['sub_editor_assignments'] = collect($article->subEditorAssignments ?? [])
            ->filter(fn ($assignment) => $canViewEditorial || (int) $assignment->sub_editor_id === (int) ($user?->id ?? 0))
            ->map(fn ($assignment) => $this->minimalAssignmentPayload($assignment, $user))
            ->values();

        $data['reviewer_assignments'] = collect($article->reviewerAssignments ?? [])
            ->filter(fn ($assignment) => $canViewReviewWorkflow || (int) $assignment->reviewer_id === (int) ($user?->id ?? 0))
            ->map(fn ($assignment) => $this->reviewerAssignmentPayload($assignment, $user, $canViewEditorial))
            ->values();

        $data['reviewer_preferences'] = $canViewReviewWorkflow
            ? $article->reviewerPreferences
                ->groupBy('type')
                ->map(fn ($items) => $items->map(fn ($item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'name' => $item->name,
                    'email' => $item->email,
                    'affiliation' => $item->affiliation,
                    'designation' => $item->designation,
                    'reason' => $item->reason,
                ])->values())
                ->union(['suggested' => collect(), 'opposed' => collect()])
            : ['suggested' => [], 'opposed' => []];

        $data['editorial_decisions'] = collect($article->editorialDecisions ?? [])
            ->map(fn ($decision) => $this->editorialDecisionPayload($decision, $canViewEditorial))
            ->values();

        $data['production_assignments'] = collect($article->productionAssignments ?? [])
            ->filter(fn ($assignment) => $canViewProduction || (int) $assignment->user_id === (int) ($user?->id ?? 0))
            ->map(fn ($assignment) => $this->minimalAssignmentPayload($assignment, $user))
            ->values();

        $data['post_publication_actions'] = $canViewPublication
            ? collect($article->postPublicationActions ?? [])->map(fn ($action) => $this->postPublicationActionPayload($action))->values()
            : [];

        $data['audit_logs'] = $this->canViewAuditLogs($user, $article)
            ? collect($article->auditLogs ?? [])->map(fn ($log) => [
                'id' => $log->id,
                'article_id' => $log->article_id,
                'event' => $log->event,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'created_at' => $log->created_at,
            ])->values()
            : [];

        return $data;
    }

    private function assigneePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role?->name,
        ];
    }

    private function minimalAssignmentPayload(SubEditorAssignment|ReviewerAssignment|ProductionAssignment $assignment, $user): array
    {
        $payload = [
            'id' => $assignment->id,
            'article_id' => $assignment->article_id,
            'status' => $assignment->status,
            'due_date' => $assignment->due_date,
            'completed_at' => $assignment->completed_at,
            'created_at' => $assignment->created_at,
        ];

        if ($assignment instanceof SubEditorAssignment) {
            $payload['sub_editor_id'] = $assignment->sub_editor_id;
            $payload['sub_editor'] = $assignment->subEditor ? ['id' => $assignment->subEditor->id, 'name' => $assignment->subEditor->name] : null;
            if ((int) $assignment->sub_editor_id === (int) ($user?->id ?? 0) || $this->isGlobal($user)) {
                $payload['recommendation'] = $assignment->recommendation;
                $payload['comments'] = $assignment->comments;
            }
        } elseif ($assignment instanceof ProductionAssignment) {
            $payload['user_id'] = $assignment->user_id;
            $payload['role'] = $assignment->role;
            $payload['user'] = $assignment->user ? ['id' => $assignment->user->id, 'name' => $assignment->user->name] : null;
        } elseif ($assignment instanceof ReviewerAssignment) {
            $payload['reviewer_id'] = $assignment->reviewer_id;
            $payload['invitee_name'] = $assignment->invitee_name;
            $payload['invitee_email'] = ($this->canViewEditorialInternals($user, $assignment->article) || $this->hasSubEditorAssignment($user, $assignment->article)) ? $assignment->invitee_email : null;
            $payload['invited_at'] = $assignment->invited_at;
            $payload['accepted_at'] = $assignment->accepted_at;
            $payload['declined_at'] = $assignment->declined_at;
            $payload['invitation_state'] = $this->reviewerInvitationState($assignment);
            $payload['reviewer'] = $assignment->reviewer ? ['id' => $assignment->reviewer->id, 'name' => $assignment->reviewer->name] : null;
            if ((int) $assignment->reviewer_id === (int) ($user?->id ?? 0) || $this->canViewEditorialInternals($user, $assignment->article)) {
                $payload['recommendation'] = $assignment->recommendation;
            }
        }

        return $payload;
    }

    private function reviewerAssignmentPayload(ReviewerAssignment $assignment, $user, bool $canViewEditorial): array
    {
        $payload = $this->minimalAssignmentPayload($assignment, $user);
        $isOwnReviewer = (int) $assignment->reviewer_id === (int) ($user?->id ?? 0);

        if ($isOwnReviewer || $canViewEditorial) {
            $payload['scorecard'] = $assignment->scorecard;
            $payload['comments_for_author'] = $assignment->comments_for_author;
        }

        if ($canViewEditorial || $isOwnReviewer) {
            $payload['confidential_comments'] = $assignment->confidential_comments;
        }

        if ($canViewEditorial || $isOwnReviewer) {
            $payload['questionnaire_instance'] = $this->questionnaireInstancePayload($assignment->questionnaireInstance);
        }

        if (!$canViewEditorial && !$isOwnReviewer) {
            unset($payload['reviewer']);
        }

        return $payload;
    }

    private function questionnaireInstancePayload(?ReviewQuestionnaireInstance $instance): ?array
    {
        if (!$instance) {
            return null;
        }
        $instance->loadMissing(['version.questions.options', 'responses']);
        $responses = $instance->responses->keyBy('review_question_id');

        return [
            'id' => $instance->id,
            'submitted_at' => $instance->submitted_at,
            'version_id' => $instance->review_questionnaire_version_id,
            'questions' => $instance->version?->questions->map(fn ($question) => [
                'id' => $question->id,
                'prompt' => $question->prompt,
                'response_type' => $question->response_type,
                'is_required' => $question->is_required,
                'options' => $question->options->map(fn ($option) => [
                    'label' => $option->label,
                    'value' => $option->value,
                ])->values(),
                'answer' => $responses->get($question->id)?->answer,
            ])->values() ?? [],
        ];
    }

    private function editorialDecisionPayload(EditorialDecision $decision, bool $includeInternal): array
    {
        $payload = [
            'id' => $decision->id,
            'article_id' => $decision->article_id,
            'decision' => $decision->decision,
            'decision_source' => $decision->decision_source,
            'decision_date' => $decision->decision_date,
            'comments_for_author' => $decision->comments_for_author,
            'decider' => $decision->decider ? ['id' => $decision->decider->id, 'name' => $decision->decider->name] : null,
        ];

        if ($includeInternal) {
            $payload['internal_notes'] = $decision->internal_notes;
        }

        return $payload;
    }

    private function postPublicationActionPayload(PostPublicationAction $action): array
    {
        return [
            'id' => $action->id,
            'article_id' => $action->article_id,
            'action_type' => $action->action_type,
            'reason' => $action->reason,
            'notice_text' => $action->notice_text,
            'performer' => $action->performer ? ['id' => $action->performer->id, 'name' => $action->performer->name] : null,
            'created_at' => $action->created_at,
        ];
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

        $assignmentData = $this->minimalAssignmentPayload($assignment, $user);
        $assignmentData['article'] = $articleData;
        $assignmentData['files'] = $visibleFiles;
        $assignmentData['is_overdue'] = $assignment->due_date
            && $assignment->due_date->isPast()
            && !in_array($assignment->status, ['completed'], true);

        return $assignmentData;
    }

    private function subEditorAssignmentListPayload(SubEditorAssignment $assignment): array
    {
        $article = $assignment->article;

        $primaryAction = match (ArticleStatus::normalize($article?->status ?? '')) {
            ArticleStatus::ASSIGNED_TO_SUB_EDITOR => 'continue_screening',
            ArticleStatus::UNDER_REVIEW => 'manage_reviewers',
            ArticleStatus::REVIEWER_ASSIGNED, ArticleStatus::REVIEW_IN_PROGRESS => 'review_reviewer_progress',
            ArticleStatus::SUB_EDITOR_RECOMMENDED => 'submit_recommendation',
            default => 'open_workspace',
        };
        if ($assignment->status === 'completed') {
            $primaryAction = 'view_recommendation';
        }

        return [
            'id' => $assignment->id,
            'article_id' => $assignment->article_id,
            'sub_editor_id' => $assignment->sub_editor_id,
            'status' => $assignment->status,
            'due_date' => $assignment->due_date,
            'completed_at' => $assignment->completed_at,
            'recommendation' => $assignment->recommendation,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
            'is_overdue' => (bool) ($assignment->due_date
                && $assignment->due_date->isPast()
                && !in_array($assignment->status, ['completed'], true)),
            'primary_action' => $primaryAction,
            'article' => $article ? [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'status' => $article->status,
                'created_at' => $article->created_at,
                'updated_at' => $article->updated_at,
                'magazine' => $article->magazine ? [
                    'id' => $article->magazine->id,
                    'title' => $article->magazine->title,
                    'slug' => $article->magazine->slug,
                ] : null,
            ] : null,
        ];
    }

    private function reviewerAssignmentListPayload(ReviewerAssignment $assignment): array
    {
        $article = $assignment->article;

        $primaryAction = match ($assignment->status) {
            'pending' => 'accept_decline',
            'accepted' => 'start_review',
            'in_progress' => 'continue_review',
            'completed' => 'view_submitted_review',
            'reopened' => 'continue_review',
            default => 'start_review',
        };

        return [
            'id' => $assignment->id,
            'article_id' => $assignment->article_id,
            'reviewer_id' => $assignment->reviewer_id,
            'status' => $assignment->status,
            'due_date' => $assignment->due_date,
            'accepted_at' => $assignment->accepted_at,
            'completed_at' => $assignment->completed_at,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
            'is_overdue' => (bool) ($assignment->due_date
                && $assignment->due_date->isPast()
                && !in_array($assignment->status, ['completed'], true)),
            'primary_action' => $primaryAction,
            'article' => $article ? [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'status' => $article->status,
                'created_at' => $article->created_at,
                'updated_at' => $article->updated_at,
                'magazine' => $article->magazine ? [
                    'id' => $article->magazine->id,
                    'title' => $article->magazine->title,
                    'slug' => $article->magazine->slug,
                ] : null,
            ] : null,
        ];
    }

    private function productionAssignmentListPayload(ProductionAssignment $assignment): array
    {
        $article = $assignment->article;

        return [
            'id' => $assignment->id,
            'article_id' => $assignment->article_id,
            'role' => $assignment->role,
            'status' => $assignment->status,
            'due_date' => $assignment->due_date,
            'completed_at' => $assignment->completed_at,
            'created_at' => $assignment->created_at,
            'updated_at' => $assignment->updated_at,
            'is_overdue' => $assignment->due_date
                && $assignment->due_date->isPast()
                && !in_array($assignment->status, ['completed'], true),
            'article' => $article ? [
                'id' => $article->id,
                'title' => $article->title,
                'status' => $article->status,
                'created_at' => $article->created_at,
                'updated_at' => $article->updated_at,
                'magazine' => $article->magazine ? [
                    'id' => $article->magazine->id,
                    'title' => $article->magazine->title,
                    'slug' => $article->magazine->slug,
                ] : null,
            ] : null,
        ];
    }

    private function isGlobal($user): bool
    {
        return $user && ($user->hasRole('super_admin') || $user->hasRole('admin'));
    }

    private function rejectObserverMutation(Request $request): void
    {
        if ($request->has('observer_user_id')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Observer mode is read-only. Clear observer mode before performing workflow actions.',
            ], 422));
        }
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

    private function hasProductionAssignment($user, Article $article, ?string $role = null): bool
    {
        return DB::table('production_assignments')
            ->where('article_id', $article->id)
            ->where('user_id', $user->id)
            ->when($role, fn ($query) => $query->where('role', $role))
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
