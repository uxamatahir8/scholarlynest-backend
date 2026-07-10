<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Events\ArticleWorkflowEventOccurred;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleTransferRequest;
use App\Models\Magazine;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArticleTransferService
{
    public function getEligibleTargetMagazines(Article $article): EloquentCollection
    {
        return Magazine::query()
            ->select(['id', 'title', 'slug'])
            ->where('id', '!=', $article->magazine_id)
            ->where(function ($query) {
                $query->where('is_active', true)
                    ->orWhereNull('is_active');
            })
            ->orderBy('title')
            ->get();
    }

    public function createTransferRequest(Article $article, Magazine $targetMagazine, User $requestedBy, string $comments): ArticleTransferRequest
    {
        $this->assertCanCreate($article, $targetMagazine);

        $transferRequest = DB::transaction(function () use ($article, $targetMagazine, $requestedBy, $comments) {
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);
            $this->assertCanCreate($article, $targetMagazine);

            $oldStatus = ArticleStatus::normalize($article->status);

            $transferRequest = ArticleTransferRequest::create([
                'article_id' => $article->id,
                'from_magazine_id' => $article->magazine_id,
                'to_magazine_id' => $targetMagazine->id,
                'requested_by_user_id' => $requestedBy->id,
                'status' => ArticleTransferRequest::STATUS_PENDING,
                'editor_comments' => $comments,
                'previous_article_status' => $oldStatus,
                'next_article_status' => ArticleStatus::SCREENING,
                'requested_at' => now(),
            ]);

            $article->update(['status' => ArticleStatus::IN_TRANSIT]);

            $this->audit($article, $requestedBy->id, 'transfer.requested', $oldStatus, ArticleStatus::IN_TRANSIT, [
                'transfer_request_id' => $transferRequest->id,
                'from_magazine_id' => $transferRequest->from_magazine_id,
                'to_magazine_id' => $transferRequest->to_magazine_id,
                'editor_comments' => $comments,
            ]);

            return $transferRequest;
        });

        event(new ArticleWorkflowEventOccurred(
            $article->fresh(['magazine', 'articleAuthors', 'user']),
            'transfer.requested',
            $requestedBy,
            [
                'transfer_request_id' => $transferRequest->id,
                'from_status' => $transferRequest->previous_article_status,
                'to_status' => ArticleStatus::IN_TRANSIT,
                'from_magazine_id' => $transferRequest->from_magazine_id,
                'to_magazine_id' => $transferRequest->to_magazine_id,
                'target_magazine' => $targetMagazine->title,
                'editor_comments' => $comments,
            ]
        ));

        return $transferRequest->fresh(['fromMagazine:id,title,slug', 'toMagazine:id,title,slug', 'requestedBy:id,name,email']);
    }

    public function acceptTransfer(Article $article, ArticleTransferRequest $transferRequest, User $respondedBy): ArticleTransferRequest
    {
        $this->assertPendingResponse($article, $transferRequest);
        $targetMagazine = Magazine::findOrFail($transferRequest->to_magazine_id);

        if (!($targetMagazine->is_active ?? true)) {
            throw ValidationException::withMessages([
                'to_magazine_id' => ['The target magazine is no longer active.'],
            ]);
        }

        $transferRequest = DB::transaction(function () use ($article, $transferRequest, $respondedBy, $targetMagazine) {
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);
            $transferRequest = ArticleTransferRequest::query()->lockForUpdate()->findOrFail($transferRequest->id);
            $this->assertPendingResponse($article, $transferRequest);

            $oldMagazineId = $article->magazine_id;
            $article->update([
                'magazine_id' => $targetMagazine->id,
                'magazine_issue_id' => null,
                'status' => ArticleStatus::SCREENING,
            ]);

            $transferRequest->update([
                'status' => ArticleTransferRequest::STATUS_ACCEPTED,
                'responded_by_user_id' => $respondedBy->id,
                'responded_at' => now(),
                'next_article_status' => ArticleStatus::SCREENING,
            ]);

            $this->audit($article, $respondedBy->id, 'transfer.accepted', ArticleStatus::IN_TRANSIT, ArticleStatus::SCREENING, [
                'transfer_request_id' => $transferRequest->id,
                'from_magazine_id' => $oldMagazineId,
                'to_magazine_id' => $targetMagazine->id,
            ]);
            $this->audit($article, $respondedBy->id, 'transfer.magazine_changed', ArticleStatus::IN_TRANSIT, ArticleStatus::SCREENING, [
                'transfer_request_id' => $transferRequest->id,
                'from_magazine_id' => $oldMagazineId,
                'to_magazine_id' => $targetMagazine->id,
            ]);

            return $transferRequest;
        });

        event(new ArticleWorkflowEventOccurred(
            $article->fresh(['magazine', 'articleAuthors', 'user']),
            'transfer.accepted',
            $respondedBy,
            [
                'transfer_request_id' => $transferRequest->id,
                'from_status' => ArticleStatus::IN_TRANSIT,
                'to_status' => ArticleStatus::SCREENING,
                'from_magazine_id' => $transferRequest->from_magazine_id,
                'to_magazine_id' => $transferRequest->to_magazine_id,
                'requested_by_user_id' => $transferRequest->requested_by_user_id,
            ]
        ));

        return $transferRequest->fresh(['fromMagazine:id,title,slug', 'toMagazine:id,title,slug', 'requestedBy:id,name,email', 'respondedBy:id,name,email']);
    }

    public function rejectTransfer(Article $article, ArticleTransferRequest $transferRequest, User $respondedBy, string $reason): ArticleTransferRequest
    {
        $this->assertPendingResponse($article, $transferRequest);

        $transferRequest = DB::transaction(function () use ($article, $transferRequest, $respondedBy, $reason) {
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);
            $transferRequest = ArticleTransferRequest::query()->lockForUpdate()->findOrFail($transferRequest->id);
            $this->assertPendingResponse($article, $transferRequest);

            $article->update(['status' => ArticleStatus::SCREENING]);

            $transferRequest->update([
                'status' => ArticleTransferRequest::STATUS_REJECTED,
                'responded_by_user_id' => $respondedBy->id,
                'responded_at' => now(),
                'author_rejection_reason' => $reason,
                'next_article_status' => ArticleStatus::SCREENING,
            ]);

            $this->audit($article, $respondedBy->id, 'transfer.rejected', ArticleStatus::IN_TRANSIT, ArticleStatus::SCREENING, [
                'transfer_request_id' => $transferRequest->id,
                'from_magazine_id' => $transferRequest->from_magazine_id,
                'to_magazine_id' => $transferRequest->to_magazine_id,
                'author_rejection_reason' => $reason,
            ]);

            return $transferRequest;
        });

        event(new ArticleWorkflowEventOccurred(
            $article->fresh(['magazine', 'articleAuthors', 'user']),
            'transfer.rejected',
            $respondedBy,
            [
                'transfer_request_id' => $transferRequest->id,
                'from_status' => ArticleStatus::IN_TRANSIT,
                'to_status' => ArticleStatus::SCREENING,
                'from_magazine_id' => $transferRequest->from_magazine_id,
                'to_magazine_id' => $transferRequest->to_magazine_id,
                'requested_by_user_id' => $transferRequest->requested_by_user_id,
                'author_rejection_reason' => $reason,
            ]
        ));

        return $transferRequest->fresh(['fromMagazine:id,title,slug', 'toMagazine:id,title,slug', 'requestedBy:id,name,email', 'respondedBy:id,name,email']);
    }

    private function assertCanCreate(Article $article, Magazine $targetMagazine): void
    {
        if (!in_array(ArticleStatus::normalize($article->status), [ArticleStatus::SUBMITTED, ArticleStatus::SCREENING], true)) {
            throw ValidationException::withMessages([
                'article' => ['Article transfers can only be requested during Screening.'],
            ]);
        }

        if ((int) $article->magazine_id === (int) $targetMagazine->id) {
            throw ValidationException::withMessages([
                'to_magazine_id' => ['Choose a different target magazine.'],
            ]);
        }

        if (!($targetMagazine->is_active ?? true)) {
            throw ValidationException::withMessages([
                'to_magazine_id' => ['The target magazine must be active.'],
            ]);
        }

        if ($article->transferRequests()->where('status', ArticleTransferRequest::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages([
                'article' => ['This article already has a pending transfer request.'],
            ]);
        }
    }

    private function assertPendingResponse(Article $article, ArticleTransferRequest $transferRequest): void
    {
        if ((int) $transferRequest->article_id !== (int) $article->id) {
            throw ValidationException::withMessages(['transfer_request' => ['Transfer request does not belong to this article.']]);
        }

        if ($transferRequest->status !== ArticleTransferRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['transfer_request' => ['This transfer request has already been resolved.']]);
        }

        if (ArticleStatus::normalize($article->status) !== ArticleStatus::IN_TRANSIT) {
            throw ValidationException::withMessages(['article' => ['Article is not waiting for transfer approval.']]);
        }
    }

    private function audit(Article $article, ?int $actorId, string $event, ?string $fromStatus, ?string $toStatus, array $payload = []): void
    {
        ArticleAuditLog::create([
            'article_id' => $article->id,
            'actor_id' => $actorId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'payload' => $payload,
        ]);
    }
}
