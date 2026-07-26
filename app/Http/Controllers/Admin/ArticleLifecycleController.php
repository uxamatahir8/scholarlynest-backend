<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LifecycleCommandRequest;
use App\Models\Article;
use App\Models\ProductionAssignment;
use App\Models\ProofRound;
use App\Models\PublicationRecord;
use App\Models\ReviewerAssignment;
use App\Models\SubEditorAssignment;
use App\Services\EditorialDecisionService;
use App\Services\ProductionWorkflowService;
use App\Services\ProofWorkflowService;
use App\Services\PublicationService;
use App\Services\ReviewerWorkflowService;
use App\Services\ScreeningService;
use App\Services\SubEditorWorkflowService;
use Illuminate\Http\JsonResponse;

class ArticleLifecycleController extends Controller
{
    public function screen(LifecycleCommandRequest $request, Article $article, ScreeningService $service): JsonResponse
    {
        $this->authorizeEditorial($request, $article);

        return $this->result($service->decide($article, $request->user(), $request->integer('article_version_id'), $request->string('decision')->toString(), $request->input('reason'), $request->idempotencyKey()));
    }

    public function assignSubEditor(LifecycleCommandRequest $request, Article $article, SubEditorWorkflowService $service): JsonResponse
    {
        $this->authorizeEditorial($request, $article);

        return $this->result($service->assign($article, $request->user(), $request->integer('article_version_id'), $request->integer('sub_editor_id'), $request->input('due_at'), $request->idempotencyKey()));
    }

    public function recommend(LifecycleCommandRequest $request, SubEditorAssignment $assignment, SubEditorWorkflowService $service): JsonResponse
    {
        if ((int) $assignment->sub_editor_id !== (int) $request->user()->id) {
            abort(403);
        }

        return $this->result($service->recommend($assignment, $request->user(), $request->string('recommendation')->toString(), $request->input('author_comments'), $request->input('internal_comments'), $request->idempotencyKey()));
    }

    public function inviteReviewer(LifecycleCommandRequest $request, Article $article, ReviewerWorkflowService $service): JsonResponse
    {
        $this->authorizeEditorial($request, $article);

        return $this->result($service->invite($article, $request->user(), $request->integer('article_version_id'), $request->integer('reviewer_id') ?: null, $request->input('name'), $request->input('email'), $request->input('due_at'), $request->idempotencyKey()));
    }

    public function reviewResponse(LifecycleCommandRequest $request, ReviewerAssignment $assignment, ReviewerWorkflowService $service): JsonResponse
    {
        if ((int) $assignment->reviewer_id !== (int) $request->user()->id) {
            abort(403);
        }

        return $this->result($service->respond($assignment, $request->user(), $request->input('decision') === 'accept', $request->input('reason'), $request->idempotencyKey()));
    }

    public function submitReview(LifecycleCommandRequest $request, ReviewerAssignment $assignment, ReviewerWorkflowService $service): JsonResponse
    {
        if ((int) $assignment->reviewer_id !== (int) $request->user()->id) {
            abort(403);
        }

        return $this->result($service->submit($assignment, $request->user(), $request->string('recommendation')->toString(), $request->string('author_comments')->toString(), $request->input('confidential_comments'), $request->idempotencyKey()));
    }

    public function reopenReview(LifecycleCommandRequest $request, ReviewerAssignment $assignment, ReviewerWorkflowService $service): JsonResponse
    {
        $this->authorizeEditorial($request, $assignment->article);

        return $this->result($service->reopen($assignment, $request->user(), $request->idempotencyKey()));
    }

    public function editorialDecision(LifecycleCommandRequest $request, Article $article, EditorialDecisionService $service): JsonResponse
    {
        $this->authorizeEditorial($request, $article);

        return $this->result($service->submit($article, $request->user(), $request->integer('article_version_id'), $request->string('decision')->toString(), $request->string('decision_source')->toString(), $request->input('author_comments'), $request->input('internal_notes'), $request->input('revision_due_at'), $request->idempotencyKey()));
    }

    public function assignCopyEditor(LifecycleCommandRequest $request, Article $article, ProductionWorkflowService $service): JsonResponse
    {
        $this->authorizePublisher($request, $article);

        return $this->result($service->assignCopyEditor($article, $request->user(), $request->integer('copy_editor_id'), $request->input('due_at'), $request->idempotencyKey()));
    }

    public function completeCopyediting(LifecycleCommandRequest $request, ProductionAssignment $assignment, ProductionWorkflowService $service): JsonResponse
    {
        if ((int) $assignment->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        return $this->result($service->complete($assignment, $request->user(), $request->integer('copyedited_file_id'), $request->input('notes'), $request->idempotencyKey()));
    }

    public function requestProof(LifecycleCommandRequest $request, Article $article, ProofWorkflowService $service): JsonResponse
    {
        $this->authorizePublisher($request, $article);

        return $this->result($service->request($article, $request->user(), $request->integer('source_file_id'), $request->input('due_at'), $request->idempotencyKey()));
    }

    public function proofResponse(LifecycleCommandRequest $request, ProofRound $proof, ProofWorkflowService $service): JsonResponse
    {
        if ($request->user()->cannot('view', $proof->article)) {
            abort(403);
        }

        return $this->result($service->authorRespond($proof, $request->user(), $request->string('decision')->toString(), $request->input('comments'), $request->integer('article_file_id') ?: null, $request->idempotencyKey()));
    }

    public function correctProof(LifecycleCommandRequest $request, ProofRound $proof, ProofWorkflowService $service): JsonResponse
    {
        if (! $proof->article->productionAssignments()->where('user_id', $request->user()->id)->whereNull('revoked_at')->exists() && $request->user()->cannot('approve', $proof->article)) {
            abort(403);
        }

        return $this->result($service->correct($proof, $request->user(), $request->integer('article_file_id'), $request->input('notes'), $request->idempotencyKey()));
    }

    public function preparePublication(LifecycleCommandRequest $request, Article $article, PublicationService $service): JsonResponse
    {
        $this->authorizePublisher($request, $article);

        return $this->result($service->prepare($article, $request->user(), $request->validated(), $request->idempotencyKey()));
    }

    public function selectPublicationFiles(LifecycleCommandRequest $request, PublicationRecord $publication, PublicationService $service): JsonResponse
    {
        $this->authorizePublisher($request, $publication->article);

        return $this->result($service->selectFiles($publication, $request->user(), $request->validated('selections'), $request->idempotencyKey()));
    }

    public function publish(LifecycleCommandRequest $request, PublicationRecord $publication, PublicationService $service): JsonResponse
    {
        $this->authorizePublisher($request, $publication->article);

        return $this->result($service->publish($publication, $request->user(), $request->idempotencyKey()));
    }

    public function unpublish(LifecycleCommandRequest $request, PublicationRecord $publication, PublicationService $service): JsonResponse
    {
        $this->authorizePublisher($request, $publication->article);

        return $this->result($service->unpublish($publication, $request->user(), $request->string('reason')->toString(), $request->idempotencyKey()));
    }

    private function authorizeEditorial(LifecycleCommandRequest $request, Article $article, bool $superAdminOnly = false): void
    {
        if ($superAdminOnly ? ! $request->user()->hasRole(['super_admin', 'admin']) : $request->user()->cannot('approve', $article)) {
            abort(403);
        }
    }

    private function authorizePublisher(LifecycleCommandRequest $request, Article $article): void
    {
        $user = $request->user();
        if ($user->hasRole(['super_admin', 'admin'])) {
            return;
        }
        if (! $user->hasRole('publisher') || ! $user->magazines()->where('magazines.id', $article->magazine_id)->where(fn ($q) => $q->where('magazine_user.role', 'publisher')->orWhereNull('magazine_user.role'))->exists()) {
            abort(403);
        }
    }

    private function result(array $result): JsonResponse
    {
        return response()->json($result['data'], $result['status'] ?? 200)->header('Idempotent-Replay', $result['replayed'] ? 'true' : 'false');
    }
}
