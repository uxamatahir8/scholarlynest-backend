<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\EditorialDecision;
use App\Models\User;

class EditorialDecisionService
{
    public function submit(Article $article, User $actor, int $versionId, string $decision, string $source, ?string $authorComments, ?string $internalNotes, ?string $dueAt, string $key): array
    {
        $event = match ($decision) {
            'accepted' => 'article.accepted', 'rejected' => 'article.rejected', default => 'revision.requested'
        };

        return app(ArticleLifecycleService::class)->command($article, $actor, 'editorial-decision', $key, ['article_version_id' => $versionId, 'decision' => $decision], 'editorial_decision.submitted', $event,
            function (Article $locked) use ($actor, $versionId, $decision, $source, $authorComments, $internalNotes, $dueAt, $key) {
                $version = $locked->versions()->whereKey($versionId)->lockForUpdate()->first();
                if (! $version || (int) $locked->current_version_id !== $versionId) {
                    app(ArticleLifecycleService::class)->conflict('The editorial decision must target the current version.');
                }
                if ($version->screening_status !== 'passed') {
                    app(ArticleLifecycleService::class)->conflict('The current version has not passed screening.');
                }
                if (EditorialDecision::query()->where('article_version_id', $versionId)->whereNull('corrects_decision_id')->exists()) {
                    app(ArticleLifecycleService::class)->conflict('An editorial decision already exists for this version.');
                }
                $record = EditorialDecision::create(['article_id' => $locked->id, 'article_version_id' => $versionId, 'round_number' => 1, 'decision_by' => $actor->id, 'decision' => $decision, 'decision_source' => $source, 'decision_date' => now(), 'comments_for_author' => $authorComments, 'internal_notes' => $internalNotes, 'revision_due_at' => $dueAt, 'idempotency_key' => $key]);
                $status = match ($decision) {
                    'accepted' => ArticleStatus::ACCEPTED, 'rejected' => ArticleStatus::REJECTED, 'minor_revision' => ArticleStatus::MINOR_REVISION_REQUIRED, default => ArticleStatus::MAJOR_REVISION_REQUIRED
                };
                $locked->update(['status' => $status, 'rejection_reason' => in_array($decision, ['rejected', 'minor_revision', 'major_revision'], true) ? $authorComments : null]);
                if ($decision === 'accepted') {
                    $set = app(AcceptedFileSetService::class)->createForCurrentSubmission($locked, $actor);
                    $locked->update(['accepted_version_id' => $versionId, 'accepted_at' => now()->toDateString()]);
                }

                return ['decision_id' => $record->id, 'decision' => $decision, 'accepted_file_set_id' => $set->id ?? null];
            });
    }
}
