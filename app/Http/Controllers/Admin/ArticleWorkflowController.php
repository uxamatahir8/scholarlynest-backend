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
use App\Models\ArticleAcceptedFileSet;
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
use App\Models\ProofRound;
use App\Models\ReviewerAssignment;
use App\Models\ReviewQuestion;
use App\Models\ReviewQuestionnaire;
use App\Models\ReviewQuestionnaireInstance;
use App\Models\ReviewQuestionnaireVersion;
use App\Models\ReviewQuestionOption;
use App\Models\ReviewQuestionResponse;
use App\Models\Role;
use App\Models\SubEditorAssignment;
use App\Models\User;
use App\Services\AcceptedFileSetService;
use App\Services\ArticleVersionFileSectionResolver;
use App\Services\ArticleVersionService;
use App\Services\CitationService;
use App\Services\LifecycleStatusProjector;
use App\Services\Media\CleanUploadResolver;
use App\Services\Media\MediaStorageService;
use App\Services\Media\UploadValidationService;
use App\Services\Notifications\NotificationEventRecorder;
use App\Services\NotificationService;
use App\Services\PasswordSetupService;
use App\Services\PdfGeneratorService;
use App\Services\Security\HtmlSanitizer;
use App\Services\WorkflowTabManifestService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleWorkflowController extends Controller
{
    public function __construct(
        private PdfGeneratorService $pdfService,
        private ArticleVersionService $versionService,
        private AcceptedFileSetService $acceptedFileSetService,
        private CitationService $citationService,
        private PasswordSetupService $passwordSetupService
    ) {}

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
            'currentVersion',
            'proofRounds.sourceFile',
            'proofRounds.authorFile',
            'proofRounds.correctedFile',
            'publicationRecords.files.file',
            'pendingTransferRequest.fromMagazine:id,title,slug',
            'pendingTransferRequest.toMagazine:id,title,slug',
            'pendingTransferRequest.requestedBy:id,name,email',
            'pendingTransferRequest.respondedBy:id,name,email',
        ]);

        $articlePayload = $this->workflowArticlePayload($article, $request->user());

        return response()->json([
            'article' => $articlePayload,
            'files' => $articlePayload['files'] ?? [],
            'versions' => $articlePayload['versions'] ?? [],
            'capabilities' => $articlePayload['capabilities'] ?? [],
            'current_user_action' => $articlePayload['current_user_action'] ?? null,
            'unassigned_legacy_files' => $articlePayload['unassigned_legacy_files'] ?? [],
            'workflow_manifest' => app(WorkflowTabManifestService::class)->manifest($article, $request->user()),
            'status_projection' => app(LifecycleStatusProjector::class)->projection($article, $request->user()),
        ]);
    }

    public function versions(Request $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'sub_editor', 'reviewer'], false);
        if ($request->user()?->hasRole('copy_editor')
            && $this->hasProductionAssignment($request->user(), $article, 'copy_editor')) {
            return response()->json(['data' => []]);
        }
        $article->load(['versions.creator:id,name', 'versions.files.uploader:id,name']);

        return response()->json([
            'data' => $this->serializedVersions($article, $request->user()),
        ]);
    }

    public function acceptedFiles(Request $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'sub_editor'], false);
        if (! $this->canViewAcceptedFileSet($request->user(), $article)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return response()->json([
            'data' => $this->acceptedFileSetPayload($article->activeAcceptedFileSet, $request->user()),
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

        if (! $this->isGlobal($user) && $magazineId && ! $this->isAssignedToMagazine($user, $magazineId, ['editor', 'sub_editor', 'publisher'])) {
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
                if (! $this->isGlobal($user) && $user->hasRole('editor')) {
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

        if (! $observedUser && ! $this->isGlobal($user) && ! $user->hasRole('sub_editor')) {
            return response()->json(['message' => 'Forbidden. Sub editor role required.'], 403);
        }

        $status = $request->query('status');
        $search = trim((string) $request->query('search'));

        $query = SubEditorAssignment::query()
            ->with([
                'article:id,magazine_id,title,slug,status,created_at,updated_at',
                'article.magazine:id,title,slug',
            ])
            ->when($observedUser || ! $this->isGlobal($user), fn ($q) => $q->where('sub_editor_id', $deskUser->id))
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
            'data' => collect($paginator->items())->map(fn (SubEditorAssignment $a) => $this->subEditorAssignmentListPayload($a))->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function myReviewerAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $observedUser = DeskObserverController::resolveObservedUser($request, 'reviewer');
        $deskUser = $observedUser ?: $user;

        if (! $observedUser && ! $this->isGlobal($user) && ! $user->hasRole('reviewer')) {
            return response()->json(['message' => 'Forbidden. Reviewer role required.'], 403);
        }

        $status = $request->query('status');
        $search = trim((string) $request->query('search'));

        $query = ReviewerAssignment::query()
            ->with([
                'article:id,magazine_id,title,slug,status,created_at,updated_at',
                'article.magazine:id,title,slug',
            ])
            ->when($observedUser || ! $this->isGlobal($user), fn ($q) => $q->where('reviewer_id', $deskUser->id))
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
            'data' => collect($paginator->items())->map(fn (ReviewerAssignment $a) => $this->reviewerAssignmentListPayload($a))->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function myProductionAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role');

        if ($role && ! in_array($role, ['copy_editor'], true)) {
            return response()->json(['message' => 'Invalid production role.'], 422);
        }

        $status = $request->query('status');
        if ($status && ! in_array($status, ['active', 'completed', 'pending'], true)) {
            return response()->json(['message' => 'Invalid assignment status.'], 422);
        }

        $observerRole = $role ?: ['copy_editor'];
        $observedUser = DeskObserverController::resolveObservedUser($request, $observerRole);
        $deskUser = $observedUser ?: $user;
        $allowedRole = $role ?: null;
        if ($observedUser) {
            $allowedRole = $role ?: 'copy_editor';
        } elseif (! $this->isGlobal($user)) {
            if (! $deskUser->hasRole('copy_editor')) {
                return response()->json(['message' => 'Forbidden. Production role required.'], 403);
            }
            $allowedRole = 'copy_editor';
            if ($role && $role !== $allowedRole) {
                return response()->json(['message' => 'Forbidden. Production role required.'], 403);
            }
        }

        $query = ProductionAssignment::query()
            ->with(['article:id,magazine_id,title,status,created_at,updated_at', 'article.magazine:id,title,slug'])
            ->when($observedUser || ! $this->isGlobal($user), fn ($q) => $q->where('user_id', $deskUser->id))
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
            'data' => collect($assignments->items())->map(fn (ProductionAssignment $a) => $this->productionAssignmentListPayload($a))->values(),
            'current_page' => $assignments->currentPage(),
            'last_page' => $assignments->lastPage(),
            'total' => $assignments->total(),
            'per_page' => $assignments->perPage(),
        ]);
    }

    public function publisherDashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $observedUser = DeskObserverController::resolveObservedUser($request, 'publisher');
        $deskUser = $observedUser ?: $user;

        if (! $observedUser && ! $this->isGlobal($user) && ! $user->hasRole('publisher')) {
            return response()->json(['message' => 'Forbidden. Publisher role required.'], 403);
        }

        $magazineIds = ($this->isGlobal($user) && ! $observedUser) ? null : $this->assignedMagazineIds($deskUser, ['publisher']);

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
        $this->rejectInTransitMutation($article);
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
            $version = $article->currentVersion()->lockForUpdate()->first()
                ?: $article->versions()->latest('version_number')->lockForUpdate()->first();
            if (! $version) {
                throw new HttpResponseException(response()->json(['message' => 'A current article version is required for screening.'], 409));
            }
            if (! $article->current_version_id) {
                $article->forceFill(['current_version_id' => $version->id])->saveQuietly();
            }
            if ($version->screening_status !== 'pending') {
                throw new HttpResponseException(response()->json(['message' => 'Screening has already been completed for this version.'], 409));
            }
            $version->update([
                'screening_status' => $request->decision === 'reject' ? 'rejected' : 'passed',
                'screened_at' => now(),
                'screened_by' => $request->user()->id,
            ]);
            if ($request->decision === 'reject') {
                EditorialDecision::create([
                    'article_id' => $article->id,
                    'article_version_id' => $version->id,
                    'round_number' => 1,
                    'decision_by' => $request->user()->id,
                    'decision' => 'rejected',
                    'decision_source' => 'screening',
                    'decision_date' => now(),
                    'comments_for_author' => $request->comments,
                ]);
            }

            $this->audit($article, $request->user()->id, 'article.screened', $oldStatus, $nextStatus, $request->validated());
            event(new ArticleWorkflowEventOccurred(
                $article->fresh(),
                $request->decision === 'reject' ? 'article.desk_rejected' : 'article.under_review',
                $request->user(),
                ['from_status' => $oldStatus, 'to_status' => $nextStatus]
            ));
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
                'message' => 'The selected Sub Editor has no Editor assignments and cannot be assigned to workflows.',
            ], 422);
        }

        // 2. Editor must be linked to the Sub-Editor, unless global
        $user = $request->user();
        if (! $this->isGlobal($user)) {
            $isLinked = $user->assignedSubEditors()->where('sub_editor_id', $subEditor->id)->exists();
            if (! $isLinked) {
                return response()->json([
                    'message' => 'You can only assign Sub Editors linked to your desk.',
                ], 422);
            }
        }

        $assignment = DB::transaction(function () use ($request, $article, $oldStatus) {
            $versionId = $article->current_version_id ?: $article->versions()->latest('version_number')->value('id');
            if (! $versionId) {
                throw new HttpResponseException(response()->json(['message' => 'A submitted article version is required.'], 409));
            }
            if (! $article->current_version_id) {
                $article->forceFill(['current_version_id' => $versionId])->saveQuietly();
            }
            SubEditorAssignment::query()->where('article_version_id', $versionId)->whereNull('revoked_at')->update(['status' => 'superseded', 'revoked_at' => now()]);
            $assignment = SubEditorAssignment::create(
                [
                    'article_id' => $article->id,
                    'article_version_id' => $versionId,
                    'round_number' => 1,
                    'sub_editor_id' => $request->sub_editor_id,
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

            $assignment->load('subEditor:id,name');
            event(new ArticleWorkflowEventOccurred($article->fresh(), 'sub_editor.assigned', $request->user(), [
                'assignment_id' => $assignment->id,
                'sub_editor' => $assignment->subEditor,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
            ]));

            return $assignment;
        });

        $assignment->load('subEditor:id,name');

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
        $version = $article->currentVersion()->first() ?: $article->versions()->latest('version_number')->first();
        if ($version && ! $article->current_version_id) {
            $article->forceFill(['current_version_id' => $version->id])->saveQuietly();
        }
        if ($version && $version->screening_status === 'pending' && in_array(ArticleStatus::normalize($article->status), [ArticleStatus::UNDER_REVIEW, ArticleStatus::ASSIGNED_TO_SUB_EDITOR, ArticleStatus::SUB_EDITOR_RECOMMENDED, ArticleStatus::REVIEWER_ASSIGNED, ArticleStatus::REVIEW_IN_PROGRESS, ArticleStatus::RESUBMITTED], true)) {
            $version->update(['screening_status' => 'passed', 'screened_at' => $article->screened_at ?: now(), 'screened_by' => $article->screened_by ?: $request->user()->id]);
        }
        if (! $version || $version->screening_status !== 'passed') {
            return response()->json(['message' => 'Reviewer invitation is prohibited until the current version passes screening.'], 409);
        }

        $existingReviewer = User::whereRaw('LOWER(email) = ?', [$inviteeEmail])->first();
        if ($existingReviewer && $existingReviewer->hasRole('reviewer')) {
            $reviewer = $existingReviewer;
        }

        $duplicate = ReviewerAssignment::query()
            ->where('article_id', $article->id)
            ->where('article_version_id', $version->id)
            ->where(function ($query) use ($reviewer, $inviteeEmail) {
                if ($reviewer) {
                    $query->where('reviewer_id', $reviewer->id);
                }
                $query->orWhereRaw('LOWER(invitee_email) = ?', [$inviteeEmail]);
            })
            ->whereNull('declined_at')
            ->where('status', '!=', 'declined')
            ->first();

        if ($duplicate) {
            return response()->json(['message' => 'This reviewer has already been invited or assigned for this article.'], 422);
        }

        $declinedAssignment = ReviewerAssignment::query()
            ->where('article_id', $article->id)
            ->where('article_version_id', $version->id)
            ->where(function ($query) use ($reviewer, $inviteeEmail) {
                if ($reviewer) {
                    $query->where('reviewer_id', $reviewer->id);
                }
                $query->orWhereRaw('LOWER(invitee_email) = ?', [$inviteeEmail]);
            })
            ->where(function ($query) {
                $query->whereNotNull('declined_at')->orWhere('status', 'declined');
            })
            ->latest('id')
            ->first();

        $rawToken = Str::random(48);

        $assignment = DB::transaction(function () use ($request, $article, $oldStatus, $reviewer, $inviteeName, $inviteeEmail, $rawToken, $declinedAssignment, $version) {
            $assignmentData = [
                'article_id' => $article->id,
                'article_version_id' => $version->id,
                'round_number' => 1,
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
                'declined_at' => null,
                'decline_reason' => null,
                'completed_at' => null,
            ];

            if ($declinedAssignment) {
                $declinedAssignment->update($assignmentData);
                $assignment = $declinedAssignment->fresh();
            } else {
                $assignment = ReviewerAssignment::create($assignmentData);
            }

            $article->update(['status' => ArticleStatus::REVIEWER_ASSIGNED]);
            $this->audit($article, $request->user()->id, 'reviewer.assigned', $oldStatus, ArticleStatus::REVIEWER_ASSIGNED, [
                'reviewer_id' => $reviewer?->id,
                'invitee_email' => $inviteeEmail,
                'due_date' => $request->due_date,
                'resent' => (bool) $declinedAssignment,
            ]);

            $assignment->load('reviewer:id,name,email');
            event(new ArticleWorkflowEventOccurred($article->fresh(), 'reviewer.assigned', $request->user(), [
                'assignment_id' => $assignment->id,
                'reviewer' => $assignment->reviewer,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::REVIEWER_ASSIGNED,
            ]));
            app(NotificationEventRecorder::class)->record(
                'reviewer.invited', $article->fresh(), $request->user(),
                array_filter([
                    'assignment_id' => $assignment->id,
                    'recipient_user_id' => $assignment->reviewer_id,
                    'recipient_privacy_variant' => 'reviewer',
                    'due_at' => $assignment->invite_expires_at?->toISOString(),
                ]),
                'reviewer_assignment', $assignment->id,
                deduplicationKey: "reviewer-invitation:{$assignment->id}:{$assignment->invited_at?->timestamp}"
            );

            return $assignment;
        });

        $assignment->load('reviewer:id,name,email');

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

        if (! $this->isGlobal($user) && (int) $assignment->sub_editor_id !== (int) $user->id) {
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
                'comments' => trim(($request->comments ?? '')."\n\nInternal notes:\n".($request->internal_notes ?? '')),
                'completed_at' => now(),
                'author_comments' => $request->comments,
                'internal_comments' => $request->internal_notes,
            ]);

            $assignment->article->update(['status' => ArticleStatus::REVIEW_IN_PROGRESS]);
            $this->audit($assignment->article, $request->user()->id, 'sub_editor.recommendation_submitted', $oldStatus, ArticleStatus::REVIEW_IN_PROGRESS, [
                'sub_editor_assignment_id' => $assignment->id,
                'recommendation' => $request->recommendation,
            ]);
            event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'sub_editor.recommendation_submitted', $request->user(), [
                'assignment_id' => $assignment->id,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
            ]));
        });

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

        if (! $this->isGlobal($user) && (int) $assignment->reviewer_id !== (int) $user->id) {
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
            event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.accepted', $request->user(), [
                'assignment_id' => $assignment->id,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
            ]));
        });

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
            event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.accepted', $user, [
                'assignment_id' => $assignment->id,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
            ]));
        });

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

        DB::transaction(function () use ($assignment, $validated) {
            $assignment->update([
                'status' => 'declined',
                'declined_at' => now(),
                'decline_reason' => $validated['decline_reason'] ?? null,
                'invite_token_hash' => null,
            ]);
            $this->audit($assignment->article, null, 'review.declined', $assignment->article->status, $assignment->article->status, [
                'reviewer_assignment_id' => $assignment->id,
            ]);
            event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.declined', null, [
                'assignment_id' => $assignment->id,
                'from_status' => $assignment->article->status,
                'to_status' => $assignment->article->status,
            ]));
        });

        return response()->json(['message' => 'Review invitation declined.']);
    }

    public function submitReview(SubmitReviewRequest $request, int $assignmentId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (! $this->isGlobal($user) && (int) $assignment->reviewer_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Reviewer assignment required.'], 403);
        }

        if (! $assignment->accepted_at && $assignment->invite_token_hash) {
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
        $recommendation = $this->recommendationFromQuestionnaire($assignment, $request->input('questionnaire_responses', []))
            ?? $request->recommendation;

        $storedFile = null;

        DB::transaction(function () use ($request, $assignment, $oldStatus, $recommendation, &$storedFile) {
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
                'recommendation' => $recommendation,
                'comments_for_author' => $request->comments_for_author,
                'confidential_comments' => $request->confidential_comments,
                'completed_at' => now(),
            ]);
            $this->persistQuestionnaireResponses($assignment->fresh(), $request->input('questionnaire_responses', []));

            $assignment->article->update(['status' => ArticleStatus::REVIEW_IN_PROGRESS]);
            $this->audit($assignment->article, $request->user()->id, 'review.submitted', $oldStatus, ArticleStatus::REVIEW_IN_PROGRESS, [
                'reviewer_assignment_id' => $assignment->id,
                'recommendation' => $recommendation,
            ]);
            event(new ArticleWorkflowEventOccurred($assignment->article->fresh(), 'review.submitted', $request->user(), [
                'assignment_id' => $assignment->id,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
                'recommendation' => $assignment->recommendation,
            ]));
        });

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

        DB::transaction(function () use ($assignment, $article, $request) {
            $assignment->update(['status' => 'reopened', 'completed_at' => null]);
            $this->audit($article, $request->user()->id, 'review.reopened', $article->status, $article->status, [
                'reviewer_assignment_id' => $assignment->id,
            ]);
            event(new ArticleWorkflowEventOccurred($article->fresh(), 'review.reopened', $request->user(), [
                'assignment_id' => $assignment->id,
                'from_status' => $article->status,
                'to_status' => $article->status,
            ]));
        });

        return response()->json([
            'message' => 'Review assignment reopened.',
            'assignment' => $this->minimalAssignmentPayload($assignment->fresh(['article.magazine:id,title,slug']), $request->user()),
        ]);
    }

    public function remindReviewer(Request $request, int $assignmentId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $assignment = ReviewerAssignment::with('article')->findOrFail($assignmentId);
        $article = $this->findAuthorizedArticle($request, $assignment->article_id, ['editor']);

        $rawToken = Str::random(48);
        DB::transaction(function () use ($assignment, $article, $request, $rawToken) {
            $assignment->update([
                'invite_token_hash' => hash('sha256', $rawToken),
                'invite_expires_at' => now()->addDays(21),
            ]);
            app(NotificationEventRecorder::class)->record(
                'review.invitation_reminded', $article->fresh(), $request->user(),
                array_filter([
                    'assignment_id' => $assignment->id,
                    'recipient_user_id' => $assignment->reviewer_id,
                    'recipient_privacy_variant' => 'reviewer',
                    'due_at' => $assignment->invite_expires_at?->toISOString(),
                ]),
                'reviewer_assignment', $assignment->id,
                deduplicationKey: "reviewer-invitation:{$assignment->id}:reminded:{$assignment->invite_expires_at?->timestamp}"
            );
        });

        $this->sendReviewerReminderInvitation($assignment, $rawToken);

        return response()->json([
            'message' => 'Reviewer reminder email sent.',
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
        $decisionEvent = match ($decisionStatus) {
            ArticleStatus::ACCEPTED => 'article.accepted',
            ArticleStatus::REJECTED => 'article.rejected',
            ArticleStatus::REVISION_REQUIRED, ArticleStatus::MINOR_REVISION_REQUIRED, ArticleStatus::MAJOR_REVISION_REQUIRED => 'revision.requested',
            default => 'editorial.decision',
        };

        $decision = DB::transaction(function () use ($request, $article, $oldStatus, $decisionStatus, $decisionEvent) {
            $version = $article->currentVersion()->lockForUpdate()->first()
                ?: $article->versions()->latest('version_number')->lockForUpdate()->first();
            if (! $version) {
                throw new HttpResponseException(response()->json(['message' => 'A current article version is required for an editorial decision.'], 409));
            }
            if (! $article->current_version_id) {
                $article->forceFill(['current_version_id' => $version->id])->saveQuietly();
            }
            if ($article->editorialDecisions()->where('article_version_id', $version->id)->exists()) {
                throw new HttpResponseException(response()->json(['message' => 'An editorial decision already exists for this version.'], 409));
            }
            $decision = EditorialDecision::create([
                'article_id' => $article->id,
                'article_version_id' => $version->id,
                'round_number' => 1,
                'decision_by' => $request->user()->id,
                'decision' => $request->decision,
                'decision_source' => $request->decision_source,
                'decision_date' => now(),
                'comments_for_author' => $request->comments_for_author,
                'internal_notes' => $request->internal_notes,
            ]);

            if ($decisionStatus === ArticleStatus::ACCEPTED) {
                $this->acceptedFileSetService->createForCurrentSubmission($article, $request->user());
                $article->accepted_version_id = $version->id;
            }

            $article->update([
                'status' => $decisionStatus,
                'rejection_reason' => $decisionStatus === ArticleStatus::REJECTED ? $request->comments_for_author : null,
                'accepted_at' => $decisionStatus === ArticleStatus::ACCEPTED && ! $article->accepted_at ? now()->toDateString() : $article->accepted_at,
            ]);

            $this->audit($article, $request->user()->id, 'editorial.decision', $oldStatus, $decisionStatus, $request->validated());
            event(new ArticleWorkflowEventOccurred($article->fresh(), $decisionEvent, $request->user(), [
                'decision_id' => $decision->id,
                'from_status' => $oldStatus,
                'to_status' => $decisionStatus,
            ]));

            return $decision;
        });

        return response()->json([
            'message' => 'Editorial decision recorded.',
            'decision' => $this->editorialDecisionPayload($decision->fresh(['decider:id,name']), true),
            'article' => $this->workflowArticlePayload($article->fresh(['magazine:id,title,slug', 'issue']), $request->user()),
        ], 201);
    }

    public function authorFinalReview(Request $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $validated = $request->validate([
            'decision' => 'nullable|string|in:accept,accepted,approve,approved,deny,denied,decline,declined',
            'reason' => 'nullable|string|max:5000',
        ]);
        $article = $this->findAuthorizedArticle($request, $articleId, ['editor', 'publisher', 'copy_editor', 'sub_editor', 'reviewer'], false);
        $user = $request->user();
        $oldStatus = ArticleStatus::normalize($article->status);
        $decision = strtolower($validated['decision'] ?? 'accepted');
        $isDenied = in_array($decision, ['deny', 'denied', 'decline', 'declined'], true);

        if ($oldStatus !== ArticleStatus::PROOFREADING) {
            return response()->json(['message' => 'Author publication review is available only after copyediting is completed.'], 422);
        }

        if ($article->author_final_approved_at) {
            return response()->json(['message' => 'This manuscript has already been approved for final review.'], 422);
        }

        if (! $this->canApproveAuthorFinalReview($user, $article)) {
            return response()->json(['message' => 'Only the manuscript owner or corresponding author may respond to publication review.'], 403);
        }

        if ($isDenied && blank($validated['reason'] ?? null)) {
            return response()->json([
                'message' => 'Please provide the changes required before denying publication.',
                'errors' => ['reason' => ['A reason is required when publication is denied.']],
            ], 422);
        }

        $nextStatus = $isDenied ? ArticleStatus::COPY_EDITING : ArticleStatus::READY_FOR_PUBLICATION;
        DB::transaction(function () use ($article, $user, $oldStatus, $nextStatus, $isDenied, $validated) {
            Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $proof = $article->proofRounds()->whereIn('status', ['awaiting_author', 'resent'])->latest('round_number')->lockForUpdate()->first();

            if ($isDenied) {
                $article->update([
                    'status' => $nextStatus,
                    'author_final_rejected_at' => now(),
                    'author_final_rejection_reason' => $validated['reason'],
                    'author_final_review_requested_at' => null,
                    'author_final_review_due_at' => null,
                ]);

                ProductionAssignment::query()
                    ->where('article_id', $article->id)
                    ->where('role', 'copy_editor')
                    ->latest('id')
                    ->limit(1)
                    ->update(['status' => 'pending', 'completed_at' => null]);
                $proof?->update(['status' => 'corrections_requested', 'responded_at' => now(), 'author_comments' => $validated['reason']]);
            } else {
                $article->update([
                    'status' => $nextStatus,
                    'author_final_approved_at' => now(),
                    'author_final_approved_by' => $user->id,
                    'author_final_rejected_at' => null,
                    'author_final_rejection_reason' => null,
                    'author_final_review_due_at' => null,
                ]);
                $proof?->update(['status' => 'approved', 'responded_at' => now(), 'approved_at' => now(), 'approved_by' => $user->id, 'active_marker' => null]);
            }

            $this->audit(
                $article,
                $user->id,
                $isDenied ? 'author.final_review_denied' : 'author.final_review_approved',
                $oldStatus,
                $nextStatus,
                $isDenied ? ['reason' => $validated['reason']] : ['approved_by' => $user->id]
            );

            $fresh = $article->fresh();
            event(new ArticleWorkflowEventOccurred($fresh, $isDenied ? 'author.final_review_denied' : 'author.final_review_approved', $user, [
                'from_status' => $oldStatus,
                'to_status' => $nextStatus,
                'reason' => $validated['reason'] ?? null,
            ]));
            if (! $isDenied) {
                event(new ArticleWorkflowEventOccurred($fresh, 'article.ready_for_publication', $user, [
                    'from_status' => $oldStatus,
                    'to_status' => $nextStatus,
                ]));
            }
        });

        $fresh = $article->fresh(['magazine:id,title,slug', 'issue', 'articleAuthors', 'files.uploader:id,name']);

        return response()->json([
            'message' => $isDenied
                ? 'Publication denied and returned to copyediting.'
                : 'Publication approved and ready for publication.',
            'article' => $this->workflowArticlePayload($fresh, $user),
        ]);
    }

    public function assignProduction(ProductionAssignmentRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $article = $this->findAuthorizedArticle($request, $articleId, ['publisher']);
        $oldStatus = $article->status;
        $nextStatus = ArticleStatus::COPY_EDITING;

        if (! $article->activeAcceptedFileSet()->exists()) {
            return response()->json(['message' => 'Create an accepted file set before assigning copyediting work.'], 422);
        }

        $assignment = DB::transaction(function () use ($request, $article, $oldStatus, $nextStatus) {
            $set = $article->activeAcceptedFileSet()->firstOrFail();
            ProductionAssignment::query()->where('article_id', $article->id)->where('role', $request->role)->whereNull('revoked_at')->update(['status' => 'superseded', 'revoked_at' => now()]);
            $assignment = ProductionAssignment::create(
                [
                    'article_id' => $article->id,
                    'article_version_id' => $set->article_version_id,
                    'accepted_file_set_id' => $set->id,
                    'user_id' => $request->user_id,
                    'role' => $request->role,
                    'assigned_by' => $request->user()->id,
                    'status' => 'pending',
                    'due_date' => $request->due_date,
                    'completed_at' => null,
                ]
            );

            $article->update(['status' => $nextStatus]);
            $this->audit($article, $request->user()->id, 'production.assigned', $oldStatus, $nextStatus, $request->validated());

            $assignment->load('user:id,name');
            event(new ArticleWorkflowEventOccurred($article->fresh(), 'production.assigned', $request->user(), [
                'assignee' => $assignment->user,
                'assignment_id' => $assignment->id,
                'from_status' => $oldStatus,
                'to_status' => $nextStatus,
            ]));

            return $assignment;
        });

        $assignment->load('user:id,name');

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
            'production_file' => 'nullable|file|mimes:'.UploadValidationService::extensionsRuleString().'|max:25600',
            'production_file_upload_id' => 'nullable|string|exists:media_upload_sessions,id',
        ]);

        $assignment = ProductionAssignment::with('article')->findOrFail($assignmentId);
        $user = $request->user();

        if (! $this->isGlobal($user) && (int) $assignment->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden. Production assignment required.'], 403);
        }

        if (! $this->isGlobal($user)
            && (! $user->hasRole('copy_editor') || $assignment->role !== 'copy_editor')) {
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
                config('media_uploads.purposes.'.$purpose),
                [
                    'assignment_type' => 'production_assignment',
                    'assignment_id' => $assignment->id,
                ]
            );
        }

        $reviewRequestedAt = now();
        DB::transaction(function () use ($assignment, $user, $oldStatus, $reviewRequestedAt, $storedFile): void {
            $assignment->update([
                'status' => 'completed',
                'completed_at' => $reviewRequestedAt,
            ]);

            $assignment->article->update([
                'status' => ArticleStatus::PROOFREADING,
                'author_final_review_requested_at' => $reviewRequestedAt,
                'author_final_review_due_at' => $reviewRequestedAt->copy()->addDays(14),
                'author_final_approved_at' => null,
                'author_final_approved_by' => null,
                'author_final_rejected_at' => null,
                'author_final_rejection_reason' => null,
                'author_final_auto_approved_at' => null,
            ]);
            $acceptedSet = $assignment->article->activeAcceptedFileSet()->first();
            $sourceFileId = $storedFile?->id ?: $acceptedSet?->items()->where('accepted_role', 'manuscript')->value('article_file_id');
            if ($acceptedSet && $sourceFileId) {
                ProofRound::firstOrCreate([
                    'article_id' => $assignment->article_id,
                    'round_number' => ((int) $assignment->article->proofRounds()->max('round_number')) + 1,
                ], [
                    'article_version_id' => $acceptedSet->article_version_id,
                    'accepted_file_set_id' => $acceptedSet->id,
                    'production_assignment_id' => $assignment->id,
                    'status' => 'awaiting_author',
                    'source_file_id' => $sourceFileId,
                    'requested_at' => $reviewRequestedAt,
                    'due_at' => $reviewRequestedAt->copy()->addDays(14),
                    'active_marker' => 1,
                ]);
            }
            $this->audit($assignment->article, $user->id, 'production.completed', $oldStatus, ArticleStatus::PROOFREADING, [
                'production_assignment_id' => $assignment->id,
                'author_review_due_at' => $reviewRequestedAt->copy()->addDays(14)->toIso8601String(),
            ]);
            $freshArticle = $assignment->article->fresh();
            event(new ArticleWorkflowEventOccurred($freshArticle, 'production.completed', $user, [
                'assignment_id' => $assignment->id,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::PROOFREADING,
            ]));
            event(new ArticleWorkflowEventOccurred($freshArticle, 'author.final_review_requested', $user, [
                'assignment_id' => $assignment->id,
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::PROOFREADING,
                'due_at' => $reviewRequestedAt->copy()->addDays(14)->toIso8601String(),
            ]));
        });

        return response()->json([
            'message' => 'Copyediting completed and sent to the author for publication approval.',
            'assignment' => $this->minimalAssignmentPayload($assignment->fresh(['article.magazine:id,title,slug']), $user),
            'article' => $this->workflowArticlePayload($assignment->article->fresh(['magazine:id,title,slug', 'issue']), $user),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function issues(Request $request): JsonResponse
    {
        if (! $this->canUseIssueManager($request->user())) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        $query = MagazineIssue::with('magazine:id,title,slug')
            ->withCount('articles')
            ->orderByDesc('published_at')
            ->orderByDesc('issue_year')
            ->orderByRaw($this->issueMonthOrderSql().' DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! $this->isGlobal($request->user())) {
            $query->whereIn('magazine_id', $this->issueManagerMagazineIds($request->user()));
        }

        if ($request->filled('magazine_id')) {
            $query->where('magazine_id', $request->integer('magazine_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function issueMagazines(Request $request): JsonResponse
    {
        if (! $this->canUseIssueManager($request->user())) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        $query = Magazine::query()->select(['id', 'title', 'slug'])->orderBy('title');

        if (! $this->isGlobal($request->user())) {
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

        if (! $this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        return response()->json(['issue' => $this->issuePayload($issue)]);
    }

    public function storeIssue(MagazineIssueRequest $request): JsonResponse
    {
        if (! $this->canUseIssueManager($request->user()) || (! $request->user()->hasRole('super_admin') && ! $this->canManageIssueMagazine($request->user(), (int) $request->magazine_id))) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        if ($this->requestChangesIssuePublicationState($request)
            && ! $this->canPublishIssueMagazine($request->user(), (int) $request->magazine_id)) {
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

        if (! $this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        if ((int) $issue->magazine_id !== (int) $request->integer('magazine_id')
            && ! $request->user()->hasRole('super_admin')
            && ! $this->canManageIssueMagazine($request->user(), $request->integer('magazine_id'))) {
            return response()->json(['message' => 'Forbidden. Issue manager assignment required for target magazine.'], 403);
        }

        if ($this->requestChangesIssuePublicationState($request)
            && ! $this->canPublishIssueMagazine($request->user(), $request->integer('magazine_id'))) {
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

        if (! $this->canPublishIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        DB::transaction(function () use ($issue, $request) {
            $issue->update([
                'status' => 'published',
                'is_published' => true,
                'published_at' => $issue->published_at ?: now(),
            ]);

            $issue->articles()->with('magazine')->chunkById(100, function ($articles) use ($request, $issue) {
                foreach ($articles as $article) {
                    app(NotificationEventRecorder::class)->record(
                        'issue.published', $article, $request->user(), ['issue_id' => $issue->id],
                        'issue', $issue->id,
                        deduplicationKey: "issue:{$issue->id}:published:article:{$article->id}:{$issue->published_at?->timestamp}"
                    );
                }
            });
        });

        return response()->json([
            'message' => 'Magazine issue published.',
            'issue' => $this->issuePayload($issue->fresh(['magazine:id,title,slug'])->loadCount('articles')),
        ]);
    }

    public function unpublishIssue(Request $request, int $issueId): JsonResponse
    {
        $issue = MagazineIssue::findOrFail($issueId);

        if (! $this->canPublishIssue($request->user(), $issue)) {
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
        if (! $this->canUseIssueManager($request->user())) {
            return response()->json(['message' => 'Forbidden. Issue Manager is restricted to Super Admin and Publisher.'], 403);
        }

        $request->validate([
            'magazine_id' => 'nullable|exists:magazines,id',
            'issue_id' => 'nullable|exists:magazine_issues,id',
        ]);

        $magazineId = $request->integer('magazine_id') ?: null;
        if ($request->filled('issue_id')) {
            $issue = MagazineIssue::findOrFail($request->integer('issue_id'));
            if (! $this->canManageIssue($request->user(), $issue)) {
                return response()->json(['message' => 'Forbidden. Issue manager assignment required.'], 403);
            }
            $magazineId = $issue->magazine_id;
        } elseif (! $this->isGlobal($request->user()) && $magazineId && ! $this->canManageIssueMagazine($request->user(), $magazineId)) {
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
        } elseif (! $this->isGlobal($request->user())) {
            $query->whereIn('magazine_id', $this->issueManagerMagazineIds($request->user()));
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (Article $article) => $this->publicationArticlePayload($article))->values()]);
    }

    public function publish(PublishArticleRequest $request, int $articleId): JsonResponse
    {
        $this->rejectObserverMutation($request);
        $request->validate([
            'publication_pdf' => 'nullable|file|mimes:'.UploadValidationService::extensionsRuleString().'|max:25600',
        ]);

        $article = $this->findAuthorizedArticle($request, $articleId, ['publisher']);
        $oldStatus = $article->status;
        $oldIssueId = $article->magazine_issue_id;
        $storedFile = null;
        $issue = $request->magazine_issue_id ? MagazineIssue::findOrFail($request->magazine_issue_id) : null;

        if (! in_array(ArticleStatus::normalize($article->status), [ArticleStatus::ACCEPTED, ArticleStatus::READY_FOR_PUBLICATION, ArticleStatus::PUBLISHED], true)) {
            return response()->json(['message' => 'Only accepted or ready-for-publication articles can be published.'], 422);
        }

        if ($issue && (int) $issue->magazine_id !== (int) $article->magazine_id) {
            return response()->json(['message' => 'The selected issue does not belong to this article magazine.'], 422);
        }

        if ($issue && ! $this->canManageIssue($request->user(), $issue)) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required for selected issue.'], 403);
        }

        DB::transaction(function () use ($request, $article, $oldStatus, $oldIssueId, &$storedFile) {
            if ($request->hasFile('publication_pdf')) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Raw browser uploads are disabled for published PDFs. Use the direct S3 upload-session flow.',
                ], 410));
            }

            if ($request->filled('publication_pdf_upload_id')) {
                $upload = app(CleanUploadResolver::class)->resolveOwned($request->user(), $request->publication_pdf_upload_id, 'article_published_pdf');
                $storedFile = app(ArticleFileController::class)->createCleanDirectUploadFile($article, $upload, config('media_uploads.purposes.article_published_pdf'));
                $article->pdf_path = $storedFile->file_path;
            } elseif ($request->filled('final_source_file_id')) {
                $sourceFile = ArticleFile::with('article')->findOrFail($request->integer('final_source_file_id'));
                $isAcceptedFile = $article->activeAcceptedFileSet()
                    ->whereHas('items', fn ($query) => $query->where('article_file_id', $sourceFile->id))
                    ->exists();
                $isProductionFile = in_array($sourceFile->file_type, [
                    ArticleFile::COPY_EDITED_FILE,
                    ArticleFile::PROOF_FILE,
                    ArticleFile::PUBLICATION_PDF,
                ], true);

                if ((int) $sourceFile->article_id !== (int) $article->id
                    || (! $isAcceptedFile && ! $isProductionFile)
                    || ($sourceFile->scan_status ?? 'clean') !== 'clean'
                    || $sourceFile->mime_type !== 'application/pdf') {
                    throw new HttpResponseException(response()->json([
                        'message' => 'The selected final publication source must be a clean PDF from the accepted or production file sets.',
                    ], 422));
                }

                $article->pdf_path = $sourceFile->storage_key ?: $sourceFile->file_path;
            }

            foreach ($request->input('publication_file_settings', []) as $setting) {
                $file = ArticleFile::where('article_id', $article->id)->find($setting['file_id']);
                if (! $file) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'A publication file selection does not belong to this article.',
                    ], 422));
                }
                $metadata = $file->metadata ?: [];
                $metadata['publication_visibility'] = [
                    'show_on_article' => (bool) ($setting['show_on_article'] ?? false),
                    'show_in_downloads' => (bool) ($setting['show_in_downloads'] ?? false),
                    'include_in_package' => (bool) ($setting['include_in_package'] ?? false),
                ];
                $file->update(['metadata' => $metadata]);
            }

            $article->update([
                'title' => $request->input('title', $article->title),
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
                $article->unsetRelation('publicationSections');
                $article->pdf_path = $this->pdfService->generate($article);
                $article->save();
            }

            $this->audit($article, $request->user()->id, 'article.published', $oldStatus, ArticleStatus::PUBLISHED, $request->validated());
            event(new ArticleWorkflowEventOccurred($article->fresh(), 'article.published', $request->user(), [
                'from_status' => $oldStatus,
                'to_status' => ArticleStatus::PUBLISHED,
            ]));

            if ($request->magazine_issue_id && (int) $oldIssueId !== (int) $request->magazine_issue_id) {
                app(NotificationEventRecorder::class)->record(
                    'article.issue_assigned', $article->fresh(), $request->user(), ['issue_id' => (int) $request->magazine_issue_id],
                    'issue', (int) $request->magazine_issue_id,
                    deduplicationKey: "article:{$article->id}:issue:{$request->magazine_issue_id}:assigned"
                );
            }
        });

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

            $this->audit($article, $request->user()->id, 'post_publication.'.$request->action_type, $oldStatus, $nextStatus, $request->validated());

            event(new ArticleWorkflowEventOccurred($article->fresh(), 'post_publication.recorded', $request->user(), [
                'action_type' => $request->action_type,
                'from_status' => $oldStatus,
                'to_status' => $nextStatus,
            ]));

            return $action;
        });

        return response()->json([
            'message' => 'Post-publication action recorded.',
            'action' => $this->postPublicationActionPayload($action->fresh(['performer:id,name'])),
            'article' => $this->publicationArticlePayload($article->fresh(['magazine:id,title,slug', 'issue', 'articleAuthors'])),
        ], 201);
    }

    public function publicationSectionImage(Request $request, int $sectionId)
    {
        $section = ArticlePublicationSection::with('article')->find($sectionId);
        if (! $section || ! $section->article || ! $section->media_upload_session_id) {
            return response()->json(['message' => 'The requested image is not available.'], 404);
        }

        if (ArticleStatus::normalize($section->article->status) !== ArticleStatus::PUBLISHED) {
            return response()->json(['message' => 'The requested image is not available.'], 404);
        }

        $upload = MediaUploadSession::whereKey($section->media_upload_session_id)
            ->where('purpose', 'publication_section_image')
            ->where('status', MediaUploadSession::STATUS_CLEAN)
            ->first();

        if (! $upload || ! $upload->s3_clean_key) {
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
        if (! $this->isGlobal($request->user())) {
            return response()->json(['message' => 'Forbidden. Super Admin access required.'], 403);
        }

        $questionnaire = ReviewQuestionnaire::with(['versions.questions.options'])
            ->latest()
            ->first();

        return response()->json(['questionnaire' => $questionnaire ? $this->questionnairePayload($questionnaire) : null]);
    }

    public function storeQuestionnaire(Request $request): JsonResponse
    {
        if (! $this->isGlobal($request->user())) {
            return response()->json(['message' => 'Forbidden. Super Admin access required.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'questions' => 'required|array|min:1',
            'questions.*.prompt' => 'required|string|max:1000',
            'questions.*.comment_helper' => 'nullable|string|max:500',
            'questions.*.response_type' => 'required|in:radio,checkbox,dropdown,single_line,textarea',
            'questions.*.is_required' => 'nullable|boolean',
            'questions.*.options' => 'nullable|array',
            'questions.*.options.*' => 'nullable|string|max:255',
        ]);

        $questionnaire = DB::transaction(function () use ($request, $validated) {
            $questionnaire = ReviewQuestionnaire::firstOrCreate(
                ['name' => $validated['name']],
                ['created_by' => $request->user()->id]
            );

            ReviewQuestionnaireVersion::where('review_questionnaire_id', $questionnaire->id)->update(['is_active' => false]);
            ReviewQuestionnaire::query()->update(['is_active' => false]);
            $versionNumber = ((int) $questionnaire->versions()->max('version_number')) + 1;
            $version = ReviewQuestionnaireVersion::create([
                'review_questionnaire_id' => $questionnaire->id,
                'version_number' => $versionNumber,
                'is_active' => true,
                'published_at' => now(),
            ]);

            foreach ($validated['questions'] as $index => $questionData) {
                $question = ReviewQuestion::create([
                    'review_questionnaire_version_id' => $version->id,
                    'prompt' => $questionData['prompt'],
                    'comment_helper' => $questionData['comment_helper'] ?? null,
                    'response_type' => $questionData['response_type'],
                    'is_required' => (bool) ($questionData['is_required'] ?? false),
                    'sort_order' => $index + 1,
                ]);

                if (in_array($question->response_type, ['radio', 'checkbox', 'dropdown'], true)) {
                    foreach (array_values(array_filter($questionData['options'] ?? [])) as $optionIndex => $optionLabel) {
                        ReviewQuestionOption::create([
                            'review_question_id' => $question->id,
                            'label' => $optionLabel,
                            'value' => Str::slug($optionLabel) ?: 'option-'.($optionIndex + 1),
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

    private function questionnairePayload(ReviewQuestionnaire $questionnaire): array
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
                    'comment_helper' => $question->comment_helper,
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

        if (! empty($validated['cover_image_upload_id'])) {
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
        if (! collect($sections)->contains(fn ($section) => ($section['section_key'] ?? null) === 'abstract')) {
            array_unshift($sections, [
                'section_key' => 'abstract',
                'title' => 'Abstract',
                'content_html' => $article->abstract ?: '',
                'sort_order' => 1,
            ]);
        }
        $sections = collect(array_values($sections))
            ->sortBy(fn ($section, $index) => (int) ($section['sort_order'] ?? ($index + 1)))
            ->values()
            ->map(fn ($section, $index) => array_merge($section, ['sort_order' => $index + 1]))
            ->all();
        $keptIds = [];

        foreach (array_values($sections) as $index => $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $html = $this->sanitizeRichText((string) ($section['content_html'] ?? ''));
            if ($title === '' && $html === '') {
                continue;
            }

            $key = Str::slug((string) ($section['section_key'] ?? '')) ?: Str::slug($title);
            $key = $key ?: 'section-'.($index + 1);
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
        if (! $email) {
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
        app(NotificationService::class)->send(
            $assignment->invitee_email,
            'Review Invitation: '.($article?->title ?? 'Article Review').' — '.($article?->magazine?->title ?? 'ScholarlyNest'),
            'Dear '.($assignment->invitee_name ?: 'Reviewer').',',
            [
                'You have been invited to provide an independent review of a manuscript submitted to <strong>'.e($article?->magazine?->title ?? 'ScholarlyNest').'</strong>.',
                '<br><strong>Manuscript Details:</strong>',
                '• <strong>Title:</strong> '.e($article?->title ?? 'Untitled Article'),
                '• <strong>Magazine:</strong> '.e($article?->magazine?->title ?? 'ScholarlyNest'),
                '• <strong>Tracking Code:</strong> '.e($article?->tracking_code ?? 'Not assigned'),
                '• <strong>Article Type:</strong> '.e($article?->article_type ?: 'Not specified'),
                '• <strong>Category:</strong> '.e($article?->article_category ?: 'Not specified'),
                '• <strong>Author Identity:</strong> Withheld under the review policy',
                '• <strong>Invited At:</strong> '.now()->format('d-M-Y H:i'),
                '<br><strong>Abstract:</strong>',
                '<div>'.nl2br(e(strip_tags((string) ($article?->abstract ?? 'Not provided.')))).'</div>',
                'Next Action: Please accept or decline this review invitation using the secure link below. If accepted, permitted manuscript files become available in your reviewer dashboard; if declined, the editorial team is notified.',
            ],
            [
                'text' => 'Open Review Invitation',
                'url' => "{$frontendUrl}/review-invitations/{$assignment->id}?token={$rawToken}",
            ],
            'default',
            $assignment->reviewer_id
        );
    }

    private function sendReviewerReminderInvitation(ReviewerAssignment $assignment, string $rawToken): void
    {
        $frontendUrl = rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/');
        $article = $assignment->article()->with(['magazine:id,title', 'articleAuthors'])->first();
        app(NotificationService::class)->send(
            $assignment->invitee_email,
            'Reminder: Review Invitation: '.($article?->title ?? 'Article Review').' — '.($article?->magazine?->title ?? 'ScholarlyNest'),
            'Dear '.($assignment->invitee_name ?: 'Reviewer').',',
            [
                'You have been invited to provide an independent review of a manuscript submitted to <strong>'.e($article?->magazine?->title ?? 'ScholarlyNest').'</strong>.',
                '<br><strong>Manuscript Details:</strong>',
                '• <strong>Title:</strong> '.e($article?->title ?? 'Untitled Article'),
                '• <strong>Magazine:</strong> '.e($article?->magazine?->title ?? 'ScholarlyNest'),
                '• <strong>Tracking Code:</strong> '.e($article?->tracking_code ?? 'Not assigned'),
                '• <strong>Article Type:</strong> '.e($article?->article_type ?: 'Not specified'),
                '• <strong>Category:</strong> '.e($article?->article_category ?: 'Not specified'),
                '• <strong>Author Identity:</strong> Withheld under the review policy',
                '• <strong>Reminder Sent At:</strong> '.now()->format('d-M-Y H:i'),
                '<br><strong>Abstract:</strong>',
                '<div>'.nl2br(e(strip_tags((string) ($article?->abstract ?? 'Not provided.')))).'</div>',
                'Next Action: Please accept or decline this review invitation using the secure link below. If accepted, permitted manuscript files become available in your reviewer dashboard; if declined, the editorial team is notified.',
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

        app(NotificationService::class)->send(
            $user->email,
            'Reviewer Access Ready',
            'Dear '.$user->name.',',
            [
                '<strong>Your reviewer workspace is ready.</strong>',
                'Manuscript Details: Title: '.($assignment->article?->title ?? 'Untitled Article').'. Tracking Code: '.($assignment->article?->tracking_code ?? 'Not assigned').'.',
                'You may sign in with your existing account.',
                'Next Action: Open the reviewer desk to read the permitted manuscript files and complete the evaluation form.',
            ],
            [
                'text' => 'Open Reviewer Desk',
                'url' => rtrim(env('APP_URL_FRONTEND', 'http://localhost:3000'), '/').'/admin/reviewer',
            ],
            'default',
            $user->id
        );
    }

    private function assertValidInvitationToken(ReviewerAssignment $assignment, string $rawToken): void
    {
        if (
            ! $assignment->invite_token_hash
            || ! hash_equals($assignment->invite_token_hash, hash('sha256', $rawToken))
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
            if ($reviewerRole && ! $user->hasRole('reviewer')) {
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
        $version = ReviewQuestionnaireVersion::query()
            ->where('is_active', true)
            ->with('questions.options')
            ->latest('published_at')
            ->latest('id')
            ->first();

        if (! $version) {
            return null;
        }

        $instance = ReviewQuestionnaireInstance::where('reviewer_assignment_id', $assignment->id)->first();
        if ($instance) {
            if ($assignment->status !== 'completed' && $instance->review_questionnaire_version_id !== $version->id) {
                $instance->update(['review_questionnaire_version_id' => $version->id]);
            }
            if (! $assignment->questionnaire_instance_id) {
                $assignment->update(['questionnaire_instance_id' => $instance->id]);
            }

            return $instance;
        }

        $instance = ReviewQuestionnaireInstance::create([
            'reviewer_assignment_id' => $assignment->id,
            'article_id' => $assignment->article_id,
            'reviewer_id' => $assignment->reviewer_id,
            'review_questionnaire_version_id' => $version->id,
        ]);
        $assignment->update(['questionnaire_instance_id' => $instance->id]);

        return $instance;
    }

    private function validateQuestionnaireResponses(ReviewerAssignment $assignment, array $responses): ?string
    {
        $instance = $this->ensureQuestionnaireInstance($assignment);
        if (! $instance) {
            return null;
        }

        $instance->loadMissing('version.questions.options');
        $answers = collect($responses)->keyBy(fn ($row) => (int) ($row['question_id'] ?? 0));
        $allowedQuestionIds = $instance->version->questions->pluck('id')->map(fn ($id) => (int) $id);
        if ($answers->keys()->diff($allowedQuestionIds)->isNotEmpty()) {
            return 'One or more questionnaire responses do not belong to this review.';
        }

        foreach ($instance->version->questions as $question) {
            $answer = $answers->get($question->id)['answer'] ?? null;
            if (! $question->is_required) {
                if ($answer === null || $answer === '') {
                    continue;
                }
            }
            if ($answer === null || $answer === '' || (is_array($answer) && count(array_filter($answer, fn ($v) => $v !== null && $v !== '')) === 0)) {
                return 'Please answer all required reviewer questionnaire questions before submitting your review.';
            }
            if ($question->options->isNotEmpty()) {
                $submittedValues = is_array($answer) ? $answer : [$answer];
                $validValues = $question->options->pluck('value');
                if (collect($submittedValues)->contains(fn ($value) => ! $validValues->contains((string) $value))) {
                    return 'One or more questionnaire answers are invalid.';
                }
            }
        }

        return null;
    }

    private function persistQuestionnaireResponses(ReviewerAssignment $assignment, array $responses): void
    {
        $instance = $this->ensureQuestionnaireInstance($assignment);
        if (! $instance) {
            return;
        }

        foreach ($responses as $row) {
            $questionId = (int) ($row['question_id'] ?? 0);
            if (! $questionId) {
                continue;
            }
            ReviewQuestionResponse::updateOrCreate(
                [
                    'review_questionnaire_instance_id' => $instance->id,
                    'review_question_id' => $questionId,
                ],
                [
                    'answer' => $row['answer'] ?? null,
                    'comment' => isset($row['comment']) ? trim((string) $row['comment']) ?: null : null,
                ]
            );
        }
        $instance->update([
            'reviewer_id' => $assignment->reviewer_id,
            'submitted_at' => now(),
        ]);
    }

    private function recommendationFromQuestionnaire(ReviewerAssignment $assignment, array $responses): ?string
    {
        $instance = $this->ensureQuestionnaireInstance($assignment);
        if (! $instance) {
            return null;
        }

        $instance->loadMissing('version.questions');
        $finalQuestionId = $instance->version->questions
            ->first(fn ($question) => strcasecmp($question->prompt, 'Final Decision') === 0)?->id;
        if (! $finalQuestionId) {
            return null;
        }

        $answer = collect($responses)->firstWhere('question_id', $finalQuestionId)['answer'] ?? null;

        return match ($answer) {
            'accept', 'minor_revision', 'major_revision', 'reject' => $answer,
            'moderate_revision' => 'major_revision',
            default => null,
        };
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
            'lifecycle_status' => app(LifecycleStatusProjector::class)->canonical($article),
            'status_projection' => app(LifecycleStatusProjector::class)->projection($article, request()->user()),
            'published_year' => $article->published_year,
            'published_month' => $article->published_month,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'published_at' => $article->published_at,
            'has_pdf' => ! empty($article->pdf_path),
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
                    'has_image' => ! empty($section->media_upload_session_id),
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
        if (! $user) {
            return [];
        }

        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
            ->unique()
            ->values()
            ->all();

        $query = DB::table('magazine_user')
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

    private function findAuthorizedArticle(Request $request, int $articleId, array $roles, bool $requireAssignedRole = true): Article
    {
        $article = Article::findOrFail($articleId);
        $user = $request->user();

        if ($this->isGlobal($user)) {
            return $article;
        }

        if (! $requireAssignedRole && $article->user_id === $user->id) {
            return $article;
        }

        if (! $requireAssignedRole && $this->isArticleAuthorRecord($user, $article)) {
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
        if (! $user) {
            return false;
        }

        if ((int) $article->user_id === (int) $user->id) {
            return true;
        }

        $article->loadMissing('articleAuthors');

        return $article->articleAuthors->contains(function ($author) use ($user) {
            if (! $author->is_corresponding) {
                return false;
            }

            return (int) $author->user_id === (int) $user->id
                || strtolower((string) $author->co_author_email) === strtolower((string) $user->email);
        });
    }

    private function canRequestTransfer($user, Article $article): bool
    {
        return $user
            && in_array(ArticleStatus::normalize($article->status), [ArticleStatus::SUBMITTED, ArticleStatus::SCREENING], true)
            && ($this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor']))
            && ! $article->pendingTransferRequest;
    }

    private function canRespondTransferRequest($user, Article $article): bool
    {
        return $user
            && $user->hasRole('author')
            && ArticleStatus::normalize($article->status) === ArticleStatus::IN_TRANSIT
            && (bool) $article->pendingTransferRequest
            && (
                (int) $article->user_id === (int) $user->id
                || $article->articleAuthors->contains(function ($author) use ($user) {
                    if (! $author->is_owner && ! $author->is_corresponding) {
                        return false;
                    }

                    return (int) $author->user_id === (int) $user->id
                        || strtolower((string) $author->co_author_email) === strtolower((string) $user->email);
                })
            );
    }

    private function transferRequestPayload($transferRequest, bool $includePrivateReason = false): ?array
    {
        if (! $transferRequest) {
            return null;
        }

        return [
            'id' => $transferRequest->id,
            'article_id' => $transferRequest->article_id,
            'status' => $transferRequest->status,
            'from_magazine' => $transferRequest->fromMagazine ? [
                'id' => $transferRequest->fromMagazine->id,
                'title' => $transferRequest->fromMagazine->title,
                'slug' => $transferRequest->fromMagazine->slug,
            ] : null,
            'to_magazine' => $transferRequest->toMagazine ? [
                'id' => $transferRequest->toMagazine->id,
                'title' => $transferRequest->toMagazine->title,
                'slug' => $transferRequest->toMagazine->slug,
            ] : null,
            'requested_by' => $transferRequest->requestedBy ? [
                'id' => $transferRequest->requestedBy->id,
                'name' => $transferRequest->requestedBy->name,
            ] : null,
            'responded_by' => $transferRequest->respondedBy ? [
                'id' => $transferRequest->respondedBy->id,
                'name' => $transferRequest->respondedBy->name,
            ] : null,
            'editor_comments' => $transferRequest->editor_comments,
            'author_rejection_reason' => $includePrivateReason ? $transferRequest->author_rejection_reason : null,
            'requested_at' => optional($transferRequest->requested_at)->toISOString(),
            'responded_at' => optional($transferRequest->responded_at)->toISOString(),
        ];
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
        $resolver = app(ArticleVersionFileSectionResolver::class);

        return $article->versions
            ->sortByDesc('version_number')
            ->map(function ($version) use ($user, $resolver) {
                $serialized = $this->versionService->serializeVersion($version, $user);
                $serialized['sections'] = $resolver->groupFilesIntoSections($serialized['files'] ?? [], $version->files ?? []);

                return $serialized;
            })
            ->values()
            ->all();
    }

    private function workflowArticlePayload(Article $article, $user): array
    {
        $canViewEditorial = $this->canViewEditorialInternals($user, $article);
        $canViewReviewWorkflow = $canViewEditorial || $this->hasSubEditorAssignment($user, $article);
        $isAuthor = (int) $article->user_id === (int) ($user?->id ?? 0)
            || $this->isArticleAuthorRecord($user, $article);
        $canAuthorViewReviews = $isAuthor && in_array(ArticleStatus::normalize($article->status), [
            ArticleStatus::REVISION_REQUIRED,
            ArticleStatus::MINOR_REVISION_REQUIRED,
            ArticleStatus::MAJOR_REVISION_REQUIRED,
        ], true);
        $canViewPublication = $canViewEditorial || $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher']);
        $canViewProduction = $canViewPublication
            || $this->hasProductionAssignment($user, $article, 'copy_editor');
        $canViewAcceptedFiles = $this->canViewAcceptedFileSet($user, $article);
        $visibleFiles = app(ArticleFileController::class)->filterVisibleFiles($user, $article->files ?? []);
        $visibleSourceAssetIds = collect($visibleFiles)->pluck('source_asset_id')->filter()->map(fn ($id) => (int) $id)->all();
        $isAssignedCopyEditor = $user?->hasRole('copy_editor') && $this->hasProductionAssignment($user, $article, 'copy_editor');

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
            'open_access_label' => $article->open_access_label,
            'is_peer_reviewed' => $article->is_peer_reviewed,
            'academic_editor' => $article->academic_editor,
            'received_at' => $article->received_at,
            'accepted_at' => $article->accepted_at,
            'license_statement' => $article->license_statement,
            'competing_interests_statement' => $article->competing_interests_statement,
            'abbreviations' => $article->abbreviations,
            'citation_text' => $article->citation_text,
            'published_at' => $article->published_at,
            'published_year' => $article->published_year,
            'published_month' => $article->published_month,
            'page_start' => $article->page_start,
            'page_end' => $article->page_end,
            'has_pdf' => ! empty($article->pdf_path),
            'files' => $visibleFiles,
            'assets' => collect($article->assets ?? [])
                ->filter(fn ($asset) => ($asset->scan_status ?? 'clean') === 'clean')
                ->filter(fn ($asset) => ! $isAssignedCopyEditor || in_array((int) $asset->id, $visibleSourceAssetIds, true))
                ->map(fn ($asset) => [
                    'id' => $asset->id,
                    'asset_type' => $asset->asset_type ?: 'supplementary',
                    'title' => $asset->title,
                    'caption' => $asset->caption,
                    'description' => $asset->description,
                    'original_filename' => $asset->original_filename,
                    'file_size' => $asset->file_size,
                    'mime_type' => $asset->mime_type,
                    'created_at' => $asset->created_at,
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
                    'has_image' => ! empty($section->media_upload_session_id),
                    'image_url' => $section->media_upload_session_id ? url("/api/articles/publication-sections/{$section->id}/image") : null,
                ])
                ->sortBy('sort_order')
                ->values(),
            'versions' => $isAssignedCopyEditor ? [] : $this->serializedVersions($article, $user),
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
            'author_final_review_requested_at' => $article->author_final_review_requested_at,
            'author_final_review_due_at' => $article->author_final_review_due_at,
            'author_final_rejected_at' => $article->author_final_rejected_at,
            'author_final_rejection_reason' => $isAuthor || $canViewEditorial || $canViewProduction
                ? $article->author_final_rejection_reason
                : null,
            'author_final_auto_approved_at' => $article->author_final_auto_approved_at,
            'accepted_file_set' => $canViewAcceptedFiles
                ? $this->acceptedFileSetPayload($article->activeAcceptedFileSet, $user)
                : null,
            'proof_rounds' => ($isAuthor || $canViewEditorial || $canViewProduction)
                ? collect($article->proofRounds ?? [])->sortByDesc('round_number')->map(fn ($round) => [
                    'id' => $round->id,
                    'round_number' => $round->round_number,
                    'label' => $round->status === 'approved' ? 'Final Proof' : 'Proof '.$round->round_number,
                    'status' => $round->status,
                    'source_file_id' => $round->source_file_id,
                    'author_file_id' => $round->author_file_id,
                    'corrected_file_id' => $round->corrected_file_id,
                    'author_comments' => $round->author_comments,
                    'production_notes' => $canViewEditorial || $canViewProduction ? $round->production_notes : null,
                    'requested_at' => $round->requested_at,
                    'due_at' => $round->due_at,
                    'responded_at' => $round->responded_at,
                    'approved_at' => $round->approved_at,
                ])->values() : [],
            'publication_records' => $canViewPublication
                ? collect($article->publicationRecords ?? [])->sortByDesc('id')->map(fn ($record) => [
                    'id' => $record->id,
                    'article_version_id' => $record->article_version_id,
                    'accepted_file_set_id' => $record->accepted_file_set_id,
                    'proof_round_id' => $record->proof_round_id,
                    'magazine_issue_id' => $record->magazine_issue_id,
                    'status' => $record->status,
                    'doi' => $record->doi,
                    'page_start' => $record->page_start,
                    'page_end' => $record->page_end,
                    'scheduled_for' => $record->scheduled_for,
                    'published_at' => $record->published_at,
                    'unpublished_at' => $record->unpublished_at,
                    'files' => $record->files->map(fn ($selection) => [
                        'id' => $selection->id,
                        'article_file_id' => $selection->article_file_id,
                        'public_role' => $selection->public_role,
                        'is_primary' => $selection->is_primary,
                        'is_public' => $selection->is_public,
                    ])->values(),
                ])->values() : [],
            'can_author_final_review' => $this->canApproveAuthorFinalReview($user, $article)
                && ArticleStatus::normalize($article->status) === ArticleStatus::PROOFREADING
                && ! $article->author_final_approved_at,
            'pending_transfer_request' => $this->transferRequestPayload($article->pendingTransferRequest, $this->canViewEditorialInternals($user, $article)),
            'can_request_transfer' => $this->canRequestTransfer($user, $article),
            'can_respond_transfer_request' => $this->canRespondTransferRequest($user, $article),
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
            ->filter(fn ($assignment) => $canViewReviewWorkflow
                || (int) $assignment->reviewer_id === (int) ($user?->id ?? 0)
                || ($canAuthorViewReviews && $assignment->status === 'completed'))
            ->map(fn ($assignment) => $this->reviewerAssignmentPayload($assignment, $user, $canViewEditorial, $canAuthorViewReviews))
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
                'payload' => $log->payload,
                'created_at' => $log->created_at,
            ])->values()
            : [];

        $data['capabilities'] = [
            'view_editorial_decision' => (bool) ($canViewEditorial || ($isAuthor && collect($article->editorialDecisions)->count() > 0)),
            'view_copy_editing' => (bool) ($canViewProduction || collect($article->productionAssignments)->where('role', 'copy_editor')->count() > 0 || collect($article->files)->where('file_type', 'copy_edited_file')->count() > 0),
            'view_final_files' => (bool) ($this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor', 'publisher']) || $user?->hasRole('proofreader') || $isAuthor || collect($article->files)->whereIn('file_type', ['proof_file', 'publication_pdf'])->count() > 0),
            'view_workflow_history' => (bool) ($this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor', 'publisher'])),
        ];

        $data['current_user_action'] = $this->resolveCurrentUserAction($article, $user);

        $unassignedLegacyFiles = [];
        $hasHistoryAccess = $this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor', 'publisher']);
        if ($hasHistoryAccess) {
            $fileController = app(ArticleFileController::class);
            $unassignedFiles = collect($article->files ?? [])
                ->filter(fn ($file) => $file->article_version_id === null)
                ->filter(function ($file) use ($article) {
                    if ($file->file_type === ArticleFile::MANUSCRIPT && ArticleStatus::normalize($article->status) === ArticleStatus::DRAFT) {
                        return false;
                    }

                    return in_array($file->file_type, [ArticleFile::ADDITIONAL_MANUSCRIPT_FILE, ArticleFile::SUPPLEMENTARY, ArticleFile::MANUSCRIPT], true);
                })
                ->filter(fn ($file) => $fileController->isWorkflowReady($file))
                ->filter(fn ($file) => $fileController->canAccess($user, $file));

            foreach ($unassignedFiles as $file) {
                $unassignedLegacyFiles[] = $fileController->serializeFile($file);
            }
        }
        $data['unassigned_legacy_files'] = $unassignedLegacyFiles;

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
            if ((int) $assignment->sub_editor_id === (int) ($user?->id ?? 0)
                || $this->canViewEditorialInternals($user, $assignment->article)) {
                $payload['recommendation'] = $assignment->recommendation;
                $payload['comments'] = $assignment->comments;
            }
        } elseif ($assignment instanceof ProductionAssignment) {
            $payload['user_id'] = $assignment->user_id;
            $payload['role'] = $assignment->role;
            $payload['user'] = $assignment->user ? ['id' => $assignment->user->id, 'name' => $assignment->user->name] : null;
        } elseif ($assignment instanceof ReviewerAssignment) {
            $canViewReviewerIdentity = (int) $assignment->reviewer_id === (int) ($user?->id ?? 0)
                || $this->canViewEditorialInternals($user, $assignment->article)
                || $this->hasSubEditorAssignment($user, $assignment->article);
            $payload['reviewer_id'] = $assignment->reviewer_id;
            $payload['invitee_name'] = $canViewReviewerIdentity ? $assignment->invitee_name : null;
            $payload['invitee_email'] = $canViewReviewerIdentity ? $assignment->invitee_email : null;
            $payload['invited_at'] = $assignment->invited_at;
            $payload['accepted_at'] = $assignment->accepted_at;
            $payload['declined_at'] = $assignment->declined_at;
            $payload['invitation_state'] = $this->reviewerInvitationState($assignment);
            $payload['reviewer'] = $canViewReviewerIdentity && $assignment->reviewer ? [
                'id' => $assignment->reviewer->id,
                'name' => $assignment->reviewer->name,
                'email' => $assignment->reviewer->email,
                'affiliation' => $assignment->reviewer->university_name,
            ] : null;
            if ((int) $assignment->reviewer_id === (int) ($user?->id ?? 0) || $this->canViewEditorialInternals($user, $assignment->article)) {
                $payload['recommendation'] = $assignment->recommendation;
            }
        }

        return $payload;
    }

    private function reviewerAssignmentPayload(ReviewerAssignment $assignment, $user, bool $canViewEditorial, bool $canAuthorViewReviews = false): array
    {
        $payload = $this->minimalAssignmentPayload($assignment, $user);
        $isOwnReviewer = (int) $assignment->reviewer_id === (int) ($user?->id ?? 0);

        if ($isOwnReviewer || $canViewEditorial || $canAuthorViewReviews) {
            $payload['comments_for_author'] = $assignment->comments_for_author;
            $payload['recommendation'] = $assignment->recommendation;
        }

        if ($canViewEditorial || $isOwnReviewer) {
            $payload['confidential_comments'] = $assignment->confidential_comments;
        }

        if ($canViewEditorial || $isOwnReviewer || $canAuthorViewReviews) {
            $payload['questionnaire_instance'] = $this->questionnaireInstancePayload($assignment->questionnaireInstance);
        }

        if ($canAuthorViewReviews && ! $canViewEditorial && ! $isOwnReviewer) {
            unset(
                $payload['reviewer'],
                $payload['reviewer_id'],
                $payload['invitee_name'],
                $payload['invitee_email'],
                $payload['invited_at'],
                $payload['accepted_at'],
                $payload['declined_at']
            );
        } elseif (! $canViewEditorial && ! $isOwnReviewer) {
            unset($payload['reviewer']);
        }

        return $payload;
    }

    private function questionnaireInstancePayload(?ReviewQuestionnaireInstance $instance): ?array
    {
        if (! $instance) {
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
                'comment_helper' => $question->comment_helper,
                'response_type' => $question->response_type,
                'is_required' => $question->is_required,
                'options' => $question->options->map(fn ($option) => [
                    'label' => $option->label,
                    'value' => $option->value,
                ])->values(),
                'answer' => $responses->get($question->id)?->answer,
                'comment' => $responses->get($question->id)?->comment,
            ])->values() ?? [],
        ];
    }

    private function acceptedFileSetPayload(?ArticleAcceptedFileSet $set, $user): ?array
    {
        if (! $set) {
            return null;
        }

        $set->loadMissing(['version', 'accepter:id,name', 'items.file.uploader:id,name', 'items.sourceVersion']);
        $fileController = app(ArticleFileController::class);

        return [
            'id' => $set->id,
            'article_version_id' => $set->article_version_id,
            'selection_policy' => $set->selection_policy,
            'accepted_at' => $set->accepted_at,
            'accepted_by' => $set->accepter ? ['id' => $set->accepter->id, 'name' => $set->accepter->name] : null,
            'version' => $set->version ? [
                'id' => $set->version->id,
                'version_number' => $set->version->version_number,
                'revision_number' => $set->version->revision_number,
                'revision_tracking_code' => $set->version->revision_tracking_code,
                'label' => $set->version->label,
                'accepted_at' => $set->version->accepted_at,
            ] : null,
            'items' => $set->items
                ->filter(fn ($item) => $item->file && $fileController->canAccess($user, $item->file))
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'accepted_role' => $item->accepted_role,
                    'source_version' => $item->sourceVersion ? [
                        'id' => $item->sourceVersion->id,
                        'version_number' => $item->sourceVersion->version_number,
                        'revision_number' => $item->sourceVersion->revision_number,
                        'label' => $item->sourceVersion->label,
                    ] : null,
                    'file' => $fileController->serializeFile($item->file),
                ])->values(),
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
        return $this->canViewEditorialInternals($user, $article);
    }

    private function canViewEditorialInternals($user, Article $article): bool
    {
        return $this->isGlobal($user)
            || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor'])
            || ($user?->hasRole('sub_editor') && $this->hasSubEditorAssignment($user, $article));
    }

    private function canViewAcceptedFileSet($user, Article $article): bool
    {
        if (! $user) {
            return false;
        }

        return $this->canViewEditorialInternals($user, $article)
            || (int) $article->user_id === (int) $user->id
            || $this->isArticleAuthorRecord($user, $article)
            || $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher'])
            || $this->hasProductionAssignment($user, $article);
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
            && ! in_array($assignment->status, ['completed'], true);

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
                && ! in_array($assignment->status, ['completed'], true)),
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
                && ! in_array($assignment->status, ['completed'], true)),
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
                && ! in_array($assignment->status, ['completed'], true),
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

    private function rejectInTransitMutation(Article $article): void
    {
        if (ArticleStatus::normalize($article->status) === ArticleStatus::IN_TRANSIT) {
            throw new HttpResponseException(response()->json([
                'message' => 'This article is in transit awaiting author transfer approval. Resolve the transfer request before continuing editorial workflow actions.',
            ], 422));
        }
    }

    private function isAssignedToMagazine($user, int $magazineId, array $roles): bool
    {
        if (in_array('editor', $roles, true) && $user->isPublicationEditor()) {
            $type = Magazine::whereKey($magazineId)->value('publication_type');
            if (! in_array($type, $user->editorPublicationTypes(), true)) {
                return false;
            }
        }

        $normalizedRoles = collect($roles)
            ->map(fn ($role) => str_replace('-', '_', $role))
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

    private function resolveCurrentUserAction(Article $article, $user): ?array
    {
        if (! $user) {
            return null;
        }

        $status = ArticleStatus::normalize($article->status);
        $userId = $user->id;

        $isGlobal = $this->isGlobal($user);
        $isEditor = $isGlobal || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor']);
        $isSubEditor = $user->hasRole('sub_editor');
        $isReviewer = $user->hasRole('reviewer');
        $isPublisher = $isGlobal || $this->isAssignedToMagazine($user, $article->magazine_id, ['publisher']);
        $isAuthor = (int) $article->user_id === (int) $userId || $this->isArticleAuthorRecord($user, $article);

        if ($isEditor) {
            if (in_array($status, [ArticleStatus::SUBMITTED, ArticleStatus::RESUBMITTED], true) || $status === 'screening') {
                return [
                    'visible' => true,
                    'type' => 'screen_submission',
                    'title' => 'Screen Manuscript',
                    'description' => 'Perform plagiarism screening and decide if the manuscript proceeds to review or is rejected.',
                ];
            }
            if (in_array($status, [ArticleStatus::UNDER_REVIEW, ArticleStatus::ASSIGNED_TO_SUB_EDITOR, ArticleStatus::REVIEWER_ASSIGNED, ArticleStatus::REVIEW_IN_PROGRESS], true)) {
                $hasSubEditor = $article->subEditorAssignments()->where('status', '!=', 'completed')->exists();
                if (! $hasSubEditor && $status === ArticleStatus::UNDER_REVIEW) {
                    return [
                        'visible' => true,
                        'type' => 'assign_sub_editor',
                        'title' => 'Assign Sub Editor',
                        'description' => 'Assign a Sub Editor to manage the peer review process for this manuscript.',
                    ];
                }

                return [
                    'visible' => true,
                    'type' => 'issue_editorial_decision',
                    'title' => 'Review & Decide',
                    'description' => 'Manage reviewer assignments or issue the final editorial decision (Accept / Revision / Reject).',
                ];
            }
        }

        if ($isSubEditor) {
            $subEditorAssignment = $article->subEditorAssignments()
                ->where('sub_editor_id', $userId)
                ->where('status', '!=', 'completed')
                ->first();

            if ($subEditorAssignment) {
                if ($subEditorAssignment->status === 'pending') {
                    return [
                        'visible' => true,
                        'type' => 'accept_sub_editor_assignment',
                        'title' => 'Accept Sub Editor Assignment',
                        'description' => 'Accept the assignment to manage the review process for this manuscript.',
                        'assignment_id' => $subEditorAssignment->id,
                    ];
                } else {
                    return [
                        'visible' => true,
                        'type' => 'submit_sub_editor_recommendation',
                        'title' => 'Submit Sub Editor Recommendation',
                        'description' => 'Submit your recommendation and notes back to the chief editor.',
                        'assignment_id' => $subEditorAssignment->id,
                    ];
                }
            }
        }

        if ($isReviewer) {
            $reviewerAssignment = $article->reviewerAssignments()
                ->where('reviewer_id', $userId)
                ->where('status', '!=', 'completed')
                ->first();

            if ($reviewerAssignment) {
                if ($reviewerAssignment->status === 'pending' || ! $reviewerAssignment->accepted_at) {
                    return [
                        'visible' => true,
                        'type' => 'accept_reviewer_assignment',
                        'title' => 'Accept Review Invitation',
                        'description' => 'Please accept or decline the invitation to review this manuscript.',
                        'assignment_id' => $reviewerAssignment->id,
                    ];
                } else {
                    return [
                        'visible' => true,
                        'type' => 'submit_review',
                        'title' => 'Submit Peer Review',
                        'description' => 'Submit your structured scorecard and review comments.',
                        'assignment_id' => $reviewerAssignment->id,
                    ];
                }
            }
        }

        if ($isAuthor) {
            if (in_array($status, [ArticleStatus::REVISION_REQUIRED, ArticleStatus::MINOR_REVISION_REQUIRED, ArticleStatus::MAJOR_REVISION_REQUIRED], true)) {
                return [
                    'visible' => true,
                    'type' => 'submit_revision',
                    'title' => 'Submit Revision',
                    'description' => 'A revision has been requested. Please upload your revised manuscript and response to reviewers.',
                ];
            }
            if ($status === ArticleStatus::PROOFREADING && $this->canApproveAuthorFinalReview($user, $article) && ! $article->author_final_approved_at) {
                return [
                    'visible' => true,
                    'type' => 'submit_revision',
                    'title' => 'Author Final Approval',
                    'description' => 'Please review and approve the final copyedited version of your manuscript.',
                ];
            }
        }

        $copyEditorAssignment = $article->productionAssignments()
            ->where('user_id', $userId)
            ->where('role', 'copy_editor')
            ->where('status', '!=', 'completed')
            ->first();

        if ($copyEditorAssignment) {
            return [
                'visible' => true,
                'type' => 'upload_copy_edited_file',
                'title' => 'Copyediting Task',
                'description' => 'Upload the copy-edited version of the manuscript and mark the task complete.',
                'assignment_id' => $copyEditorAssignment->id,
            ];
        }

        $proofreaderAssignment = $article->productionAssignments()
            ->where('user_id', $userId)
            ->where('role', 'proofreader')
            ->where('status', '!=', 'completed')
            ->first();

        if ($proofreaderAssignment) {
            return [
                'visible' => true,
                'type' => 'upload_proof_file',
                'title' => 'Proofreading Task',
                'description' => 'Upload the proof-edited version of the manuscript and mark the task complete.',
                'assignment_id' => $proofreaderAssignment->id,
            ];
        }

        if ($isPublisher && $status === ArticleStatus::READY_FOR_PUBLICATION) {
            return [
                'visible' => true,
                'type' => 'finalize_publication',
                'title' => 'Finalize Publication',
                'description' => 'Assign this manuscript to an issue, configure DOI, and publish it.',
            ];
        }

        if ($isAuthor && $status === ArticleStatus::IN_TRANSIT && $article->pendingTransferRequest?->status === 'pending') {
            return [
                'visible' => true,
                'type' => 'submit_revision',
                'title' => 'Respond to Transfer Request',
                'description' => 'Decide whether to accept or decline the transfer of your manuscript to another publication.',
            ];
        }

        return null;
    }
}
