<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ProductionAssignment;
use App\Models\ProofRound;
use App\Models\User;
use App\Services\Notifications\NotificationEventRecorder;

class ProofWorkflowService
{
    public function request(Article $article, User $actor, int $sourceFileId, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($article, $actor, 'request-proof', $key, ['article_file_id' => $sourceFileId], 'proof.requested', 'author.final_review_requested', function (Article $locked) use ($sourceFileId, $key) {
            $set = $locked->activeAcceptedFileSet()->lockForUpdate()->first();
            if (! $set) {
                app(ArticleLifecycleService::class)->conflict('An accepted file set is required before proofreading.');
            }
            if (ProofRound::query()->where('article_id', $locked->id)->whereIn('status', ['awaiting_author', 'corrections_requested', 'correction_in_progress', 'resent'])->exists()) {
                app(ArticleLifecycleService::class)->conflict('An active proof round already exists.');
            }
            $file = $locked->files()->whereKey($sourceFileId)->whereIn('file_type', ['copy_edited_file', 'proof_file'])->where('scan_status', 'clean')->first();
            $assignment = $file && $file->assignment_type === 'production_assignment'
                ? ProductionAssignment::query()->whereKey($file->assignment_id)->where('article_id', $locked->id)->where('accepted_file_set_id', $set->id)->where('status', 'completed')->first()
                : null;
            if (! $file || ! $assignment) {
                app(ArticleLifecycleService::class)->conflict('The proof source must be the clean output of a completed production assignment for the active accepted file set.');
            }
            $round = ProofRound::create(['article_id' => $locked->id, 'article_version_id' => $set->article_version_id, 'accepted_file_set_id' => $set->id, 'production_assignment_id' => $assignment->id, 'round_number' => ((int) $locked->proofRounds()->max('round_number')) + 1, 'status' => 'awaiting_author', 'source_file_id' => $file->id, 'requested_at' => now(), 'idempotency_key' => $key, 'active_marker' => 1]);
            $locked->update(['status' => ArticleStatus::PROOFREADING, 'author_final_review_requested_at' => now()]);

            return ['proof_round_id' => $round->id, 'round_number' => $round->round_number];
        });
    }

    public function authorRespond(ProofRound $proof, User $actor, string $decision, ?string $comments, ?int $fileId, string $key): array
    {
        $event = $decision === 'approve' ? 'author.final_review_approved' : 'author.final_review_denied';

        return app(ArticleLifecycleService::class)->command($proof->article, $actor, 'proof-author-response', $key, ['proof_round_id' => $proof->id, 'decision' => $decision, 'article_file_id' => $fileId], 'proof.author_'.$decision, $event, function (Article $locked) use ($proof, $actor, $decision, $comments, $fileId) {
            $round = ProofRound::query()->whereKey($proof->id)->lockForUpdate()->firstOrFail();
            if (! in_array($round->status, ['awaiting_author', 'resent'], true)) {
                app(ArticleLifecycleService::class)->conflict('This proof round is not awaiting an author response.');
            }
            $author = (int) $locked->user_id === (int) $actor->id || $locked->articleAuthors()->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('co_author_email', $actor->email))->where(fn ($q) => $q->where('can_edit', true)->orWhere('is_corresponding', true))->exists();
            if (! $author) {
                app(ArticleLifecycleService::class)->conflict('Only an action-capable author can respond to this proof.');
            }
            if ($decision === 'corrections' && ! trim((string) $comments)) {
                app(ArticleLifecycleService::class)->conflict('A correction reason is required.');
            }
            if ($fileId && ! $locked->files()->whereKey($fileId)->where('uploaded_by', $actor->id)->whereIn('file_type', ['proof_file', 'annotated_manuscript'])->where('scan_status', 'clean')->exists()) {
                app(ArticleLifecycleService::class)->conflict('The annotated proof must be a clean file uploaded by the responding author for this article.');
            }
            $round->update($decision === 'approve'
                ? ['status' => 'approved', 'responded_at' => now(), 'approved_at' => now(), 'approved_by' => $actor->id, 'author_comments' => $comments, 'active_marker' => null]
                : ['status' => 'corrections_requested', 'responded_at' => now(), 'author_comments' => $comments, 'author_file_id' => $fileId]);
            if ($decision === 'corrections' && $round->production_assignment_id) {
                ProductionAssignment::query()->whereKey($round->production_assignment_id)->update(['status' => 'correction_required', 'completed_at' => null]);
            }
            $locked->update($decision === 'approve'
                ? ['status' => ArticleStatus::READY_FOR_PUBLICATION, 'author_final_approved_at' => now(), 'author_final_approved_by' => $actor->id, 'author_final_rejected_at' => null, 'author_final_rejection_reason' => null]
                : ['status' => ArticleStatus::COPY_EDITING, 'author_final_rejected_at' => now(), 'author_final_rejection_reason' => $comments]);
            if ($decision === 'approve') {
                app(NotificationEventRecorder::class)->record('article.ready_for_publication', $locked, $actor, ['article_version_id' => $round->article_version_id], deduplicationKey: 'proof-ready:'.$round->id);
            }

            return ['proof_round_id' => $round->id, 'status' => $round->status];
        });
    }

    public function correct(ProofRound $proof, User $actor, int $fileId, ?string $notes, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($proof->article, $actor, 'proof-correction', $key, ['proof_round_id' => $proof->id, 'article_file_id' => $fileId], 'proof.corrected', 'author.final_review_requested', function () use ($proof, $actor, $fileId, $notes) {
            $round = ProofRound::query()->whereKey($proof->id)->lockForUpdate()->firstOrFail();
            if (! in_array($round->status, ['corrections_requested', 'correction_in_progress'], true)) {
                app(ArticleLifecycleService::class)->conflict('This proof round is not awaiting correction.');
            }
            $assignment = ProductionAssignment::query()->whereKey($round->production_assignment_id)->where('user_id', $actor->id)->whereNull('revoked_at')->where('status', 'correction_required')->lockForUpdate()->first();
            $file = $assignment ? $round->article->files()->whereKey($fileId)->whereIn('file_type', ['copy_edited_file', 'proof_file'])->where('assignment_type', 'production_assignment')->where('assignment_id', $assignment->id)->where('scan_status', 'clean')->first() : null;
            if (! $assignment || ! $file) {
                app(ArticleLifecycleService::class)->conflict('A clean corrected proof uploaded for the active production assignment is required.');
            }
            $round->update(['status' => 'corrected', 'corrected_file_id' => $file->id, 'production_notes' => $notes, 'active_marker' => null]);
            $nextRound = ProofRound::create([
                'article_id' => $round->article_id,
                'article_version_id' => $round->article_version_id,
                'accepted_file_set_id' => $round->accepted_file_set_id,
                'production_assignment_id' => $assignment->id,
                'round_number' => ((int) $round->article->proofRounds()->max('round_number')) + 1,
                'status' => 'awaiting_author',
                'source_file_id' => $file->id,
                'requested_at' => now(),
                'active_marker' => 1,
            ]);
            $assignment->update(['status' => 'completed', 'completed_at' => now()]);
            $round->article->update([
                'status' => ArticleStatus::PROOFREADING,
                'author_final_review_requested_at' => now(),
                'author_final_rejected_at' => null,
                'author_final_rejection_reason' => null,
            ]);

            return ['proof_round_id' => $nextRound->id, 'round_number' => $nextRound->round_number, 'status' => 'awaiting_author'];
        });
    }
}
