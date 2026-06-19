<?php

namespace App\Http\Controllers\Admin;

use App\Constants\ArticleStatus;
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
use App\Models\MagazineIssue;
use App\Models\PostPublicationAction;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Models\User;
use App\Services\PdfGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ArticleWorkflowController extends Controller
{
    public function __construct(private PdfGeneratorService $pdfService)
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
        ]);

        return response()->json([
            'article' => $article,
            'files' => app(ArticleFileController::class)->filterVisibleFiles($request->user(), $article->files),
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
            ->when($magazineId && in_array($role, ['editor', 'sub_editor', 'reviewer', 'publisher'], true), function ($query) use ($magazineId, $role) {
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

        $assignments = SubEditorAssignment::query()
            ->with($this->assignmentRelations())
            ->when(!$this->isGlobal($user), fn ($query) => $query->where('sub_editor_id', $user->id))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest()
            ->get();

        return response()->json([
            'data' => $assignments->map(fn (SubEditorAssignment $assignment) => $this->assignmentPayload($assignment, $user)),
        ]);
    }

    public function myReviewerAssignments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->isGlobal($user) && !$user->hasRole('reviewer')) {
            return response()->json(['message' => 'Forbidden. Reviewer role required.'], 403);
        }

        $assignments = ReviewerAssignment::query()
            ->with($this->assignmentRelations())
            ->when(!$this->isGlobal($user), fn ($query) => $query->where('reviewer_id', $user->id))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest()
            ->get();

        return response()->json([
            'data' => $assignments->map(fn (ReviewerAssignment $assignment) => $this->assignmentPayload($assignment, $user)),
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

        return response()->json([
            'message' => 'Sub editor assigned.',
            'assignment' => $assignment->load('subEditor:id,name,email'),
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

        return response()->json([
            'message' => 'Reviewer assigned.',
            'assignment' => $assignment->load('reviewer:id,name,email'),
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

        return response()->json([
            'message' => 'Production assignment created.',
            'assignment' => $assignment->load('user:id,name,email'),
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

        return response()->json([
            'message' => 'Production assignment completed.',
            'assignment' => $assignment->fresh(),
            'article' => $assignment->article->fresh(),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function issues(Request $request): JsonResponse
    {
        $query = MagazineIssue::with('magazine:id,title,slug')->orderByDesc('created_at');

        if ($request->filled('magazine_id')) {
            $query->where('magazine_id', $request->integer('magazine_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function storeIssue(MagazineIssueRequest $request): JsonResponse
    {
        if (!$this->isGlobal($request->user()) && !$this->isAssignedToMagazine($request->user(), $request->magazine_id, ['publisher'])) {
            return response()->json(['message' => 'Forbidden. Publisher assignment required.'], 403);
        }

        $issue = MagazineIssue::create($request->validated());

        return response()->json([
            'message' => 'Magazine issue created.',
            'issue' => $issue->load('magazine:id,title,slug'),
        ], 201);
    }

    public function publish(PublishArticleRequest $request, int $articleId): JsonResponse
    {
        $request->validate([
            'publication_pdf' => 'nullable|file|mimes:pdf|max:25600',
        ]);

        $article = $this->findAuthorizedArticle($request, $articleId, ['publisher']);
        $oldStatus = $article->status;
        $storedFile = null;

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

        return response()->json([
            'message' => 'Article published.',
            'article' => $article->fresh(),
            'file' => $storedFile ? app(ArticleFileController::class)->serializeFile($storedFile) : null,
        ]);
    }

    public function postPublication(PostPublicationActionRequest $request, int $articleId): JsonResponse
    {
        $article = $this->findAuthorizedArticle($request, $articleId, ['publisher']);
        $oldStatus = $article->status;

        $action = DB::transaction(function () use ($request, $article, $oldStatus) {
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
        ];
    }

    private function assignmentPayload(SubEditorAssignment|ReviewerAssignment $assignment, $user): array
    {
        $article = $assignment->article;
        $articleData = $article->toArray();
        $visibleFiles = app(ArticleFileController::class)->filterVisibleFiles($user, $article->files);
        $articleData['files'] = $visibleFiles;

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
