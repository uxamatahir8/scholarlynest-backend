<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleTransferRequest;
use App\Models\Magazine;
use App\Models\User;
use App\Services\ArticleTransferService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleTransferController extends Controller
{
    public function __construct(private ArticleTransferService $transferService)
    {
    }

    public function targetMagazines(Request $request, Article $article): JsonResponse
    {
        $this->assertCanRequestTransfer($request->user(), $article);

        return response()->json([
            'data' => $this->transferService->getEligibleTargetMagazines($article)
                ->map(fn (Magazine $magazine) => [
                    'id' => $magazine->id,
                    'name' => $magazine->title,
                    'title' => $magazine->title,
                    'slug' => $magazine->slug,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, Article $article): JsonResponse
    {
        $this->assertCanRequestTransfer($request->user(), $article);

        $validated = $request->validate([
            'to_magazine_id' => ['required', 'integer', 'exists:magazines,id'],
            'editor_comments' => ['required', 'string', 'max:5000'],
        ]);

        $targetMagazine = Magazine::findOrFail($validated['to_magazine_id']);
        $transferRequest = $this->transferService->createTransferRequest(
            $article,
            $targetMagazine,
            $request->user(),
            $validated['editor_comments']
        );

        return response()->json([
            'message' => 'Article transfer request sent to the author.',
            'transfer_request' => $this->transferRequestPayload($transferRequest, true),
            'article' => [
                'id' => $article->id,
                'status' => ArticleStatus::IN_TRANSIT,
                'author_status' => ArticleStatus::AUTHOR_VISIBLE[ArticleStatus::IN_TRANSIT],
            ],
        ], 201);
    }

    public function show(Request $request, Article $article): JsonResponse
    {
        $transferRequest = $article->pendingTransferRequest()
            ->with(['fromMagazine:id,title,slug', 'toMagazine:id,title,slug', 'requestedBy:id,name,email', 'respondedBy:id,name,email'])
            ->first();

        if (!$transferRequest) {
            return response()->json(['transfer_request' => null]);
        }

        if (!$this->canViewTransferRequest($request->user(), $article)) {
            throw new HttpResponseException(response()->json(['message' => 'Forbidden.'], 403));
        }

        return response()->json([
            'transfer_request' => $this->transferRequestPayload($transferRequest, $this->canViewEditorialInternals($request->user(), $article)),
            'can_respond' => $this->canAuthorRespond($request->user(), $article),
        ]);
    }

    public function accept(Request $request, Article $article, ArticleTransferRequest $transferRequest): JsonResponse
    {
        $this->assertCanAuthorRespond($request->user(), $article);

        $transferRequest = $this->transferService->acceptTransfer($article, $transferRequest, $request->user());

        return response()->json([
            'message' => 'Article transfer accepted.',
            'transfer_request' => $this->transferRequestPayload($transferRequest, true),
            'article' => [
                'id' => $article->id,
                'status' => ArticleStatus::SUBMITTED,
                'magazine_id' => $transferRequest->to_magazine_id,
            ],
        ]);
    }

    public function reject(Request $request, Article $article, ArticleTransferRequest $transferRequest): JsonResponse
    {
        $this->assertCanAuthorRespond($request->user(), $article);

        $validated = $request->validate([
            'author_rejection_reason' => ['required', 'string', 'max:5000'],
        ]);

        $transferRequest = $this->transferService->rejectTransfer(
            $article,
            $transferRequest,
            $request->user(),
            $validated['author_rejection_reason']
        );

        return response()->json([
            'message' => 'Article transfer rejected.',
            'transfer_request' => $this->transferRequestPayload($transferRequest, true),
            'article' => [
                'id' => $article->id,
                'status' => ArticleStatus::SUBMITTED,
                'magazine_id' => $transferRequest->from_magazine_id,
            ],
        ]);
    }

    private function assertCanRequestTransfer(User $user, Article $article): void
    {
        if (ArticleStatus::normalize($article->status) !== ArticleStatus::SUBMITTED) {
            throw new HttpResponseException(response()->json(['message' => 'Article transfers can only be requested during Screening.'], 422));
        }

        if (!$this->canScreenCurrentMagazine($user, $article)) {
            throw new HttpResponseException(response()->json(['message' => 'Forbidden. Magazine screening assignment required.'], 403));
        }
    }

    private function assertCanAuthorRespond(User $user, Article $article): void
    {
        if (!$this->canAuthorRespond($user, $article)) {
            throw new HttpResponseException(response()->json(['message' => 'Forbidden. Author access required.'], 403));
        }
    }

    private function canViewTransferRequest(User $user, Article $article): bool
    {
        return $this->canViewEditorialInternals($user, $article) || $this->canAuthorRespond($user, $article);
    }

    private function canScreenCurrentMagazine(User $user, Article $article): bool
    {
        return $this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor', 'magazine_editor']);
    }

    private function canViewEditorialInternals(User $user, Article $article): bool
    {
        return $this->isGlobal($user) || $this->isAssignedToMagazine($user, $article->magazine_id, ['editor', 'magazine_editor']);
    }

    private function canAuthorRespond(User $user, Article $article): bool
    {
        if (ArticleStatus::normalize($article->status) !== ArticleStatus::IN_TRANSIT) {
            return false;
        }

        if ((int) $article->user_id === (int) $user->id) {
            return true;
        }

        $article->loadMissing('articleAuthors');

        return $article->articleAuthors->contains(function ($author) use ($user) {
            if (!$author->is_owner && !$author->is_corresponding) {
                return false;
            }

            return (int) $author->user_id === (int) $user->id
                || strtolower((string) $author->co_author_email) === strtolower((string) $user->email);
        });
    }

    private function isGlobal(User $user): bool
    {
        return $user->hasRole(['super_admin', 'admin']);
    }

    private function isAssignedToMagazine(User $user, ?int $magazineId, array $roles): bool
    {
        if (!$magazineId) {
            return false;
        }

        return $user->magazines()
            ->where('magazines.id', $magazineId)
            ->where(function ($query) use ($roles) {
                $query->whereIn('magazine_user.role', $roles)
                    ->orWhereNull('magazine_user.role');
            })
            ->exists();
    }

    private function transferRequestPayload(ArticleTransferRequest $transferRequest, bool $includePrivateReason = false): array
    {
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
}
