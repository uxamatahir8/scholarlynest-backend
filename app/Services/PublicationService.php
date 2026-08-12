<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\PublicationFileSelection;
use App\Models\PublicationRecord;
use App\Models\User;

class PublicationService
{
    public function prepare(Article $article, User $actor, array $data, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($article, $actor, 'prepare-publication', $key, $data, 'publication.prepared', 'article.issue_assigned', function (Article $locked) use ($actor, $data, $key) {
            $set = $locked->activeAcceptedFileSet()->lockForUpdate()->first();
            $proof = $locked->proofRounds()->where('status', 'approved')->latest('round_number')->first();
            if (! $set || ! $proof) {
                app(ArticleLifecycleService::class)->conflict('An approved proof and active accepted file set are required for publication preparation.');
            }
            if (! empty($data['scheduled_for']) && empty($data['magazine_issue_id'])) {
                app(ArticleLifecycleService::class)->conflict('Issue assignment is required before publication can be scheduled.');
            }
            if (! empty($data['page_start']) && ! empty($data['page_end']) && (int) $data['page_start'] > (int) $data['page_end']) {
                app(ArticleLifecycleService::class)->conflict('The page range is invalid.');
            }
            if (! empty($data['magazine_issue_id']) && ! empty($data['page_start']) && $this->pageOverlap((int) $data['magazine_issue_id'], (int) $data['page_start'], (int) $data['page_end'], $locked->id)) {
                app(ArticleLifecycleService::class)->conflict('The page range overlaps another article in this issue.');
            }
            $record = PublicationRecord::create([
                'article_id' => $locked->id, 'article_version_id' => $set->article_version_id,
                'accepted_file_set_id' => $set->id, 'proof_round_id' => $proof->id,
                'magazine_issue_id' => $data['magazine_issue_id'] ?? null,
                'status' => empty($data['magazine_issue_id']) ? 'preparing' : (! empty($data['scheduled_for']) ? 'scheduled' : 'issue_assigned'),
                'doi' => $data['doi'] ?? null, 'page_start' => $data['page_start'] ?? null, 'page_end' => $data['page_end'] ?? null,
                'scheduled_for' => $data['scheduled_for'] ?? null, 'created_by' => $actor->id, 'idempotency_key' => $key,
            ]);
            $locked->update(['magazine_issue_id' => $record->magazine_issue_id, 'doi' => $record->doi, 'page_start' => $record->page_start, 'page_end' => $record->page_end]);

            return ['publication_record_id' => $record->id];
        });
    }

    public function selectFiles(PublicationRecord $record, User $actor, array $selections, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($record->article, $actor, 'select-publication-files', $key, ['publication_record_id' => $record->id, 'file_ids' => array_column($selections, 'article_file_id')], 'publication.files_selected', 'article_file.available', function () use ($record, $actor, $selections) {
            $locked = PublicationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['published', 'unpublished'], true)) {
                app(ArticleLifecycleService::class)->conflict('Published file selections are immutable.');
            }
            if (collect($selections)->where('is_primary', true)->count() !== 1) {
                app(ArticleLifecycleService::class)->conflict('Exactly one primary public manuscript PDF must be selected.');
            }
            $eligible = $this->eligibleFileIds($locked);
            foreach ($selections as $selection) {
                if (! in_array((int) $selection['article_file_id'], $eligible, true)) {
                    app(ArticleLifecycleService::class)->conflict('A selected file is not an approved publication candidate.');
                }
                if (! empty($selection['is_primary']) && ($selection['public_role'] ?? null) !== 'primary_manuscript') {
                    app(ArticleLifecycleService::class)->conflict('The primary file must use the primary manuscript role.');
                }
            }
            $locked->files()->delete();
            foreach ($selections as $selection) {
                PublicationFileSelection::create([
                    'publication_record_id' => $locked->id, 'article_file_id' => $selection['article_file_id'],
                    'public_role' => $selection['public_role'], 'is_primary' => (bool) ($selection['is_primary'] ?? false),
                    'primary_marker' => ! empty($selection['is_primary']) ? 1 : null,
                    'is_public' => (bool) ($selection['is_public'] ?? true), 'selected_by' => $actor->id,
                ]);
            }

            return ['publication_record_id' => $locked->id, 'selection_count' => count($selections)];
        });
    }

    public function publish(PublicationRecord $record, User $actor, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($record->article, $actor, 'publish-article', $key, ['publication_record_id' => $record->id], 'article.published', 'article.published', function (Article $article) use ($record, $actor) {
            $locked = PublicationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'published') {
                return ['publication_record_id' => $locked->id, 'published_at' => $locked->published_at];
            }
            if (! $locked->magazine_issue_id) {
                app(ArticleLifecycleService::class)->conflict('Issue assignment is required before publication.');
            }
            if (! $locked->files()->where('is_primary', true)->where('is_public', true)->exists() || $locked->files()->where('is_primary', true)->count() !== 1) {
                app(ArticleLifecycleService::class)->conflict('Exactly one public primary manuscript PDF is required.');
            }
            if ($locked->scheduled_for && $locked->scheduled_for->isFuture()) {
                app(ArticleLifecycleService::class)->conflict('The scheduled publication time has not arrived.');
            }
            $locked->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->id]);
            $article->update(['status' => ArticleStatus::PUBLISHED, 'published_at' => now(), 'magazine_issue_id' => $locked->magazine_issue_id, 'doi' => $locked->doi, 'page_start' => $locked->page_start, 'page_end' => $locked->page_end]);

            return ['publication_record_id' => $locked->id, 'published_at' => $locked->published_at];
        });
    }

    public function unpublish(PublicationRecord $record, User $actor, string $reason, string $key): array
    {
        return app(ArticleLifecycleService::class)->command($record->article, $actor, 'unpublish-article', $key, ['publication_record_id' => $record->id], 'article.unpublished', 'post_publication.recorded', function (Article $article) use ($record, $reason) {
            $locked = PublicationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'published') {
                app(ArticleLifecycleService::class)->conflict('Only a published article can be unpublished.');
            }
            if (! trim($reason)) {
                app(ArticleLifecycleService::class)->conflict('An unpublication reason is required.');
            }
            $locked->update(['status' => 'unpublished', 'unpublished_at' => now(), 'unpublish_reason' => $reason]);
            $article->update(['status' => ArticleStatus::WITHDRAWN]);

            return ['publication_record_id' => $locked->id, 'status' => 'unpublished'];
        });
    }

    public function eligibleFileIds(PublicationRecord $record): array
    {
        $accepted = $record->acceptedFileSet->items()->pluck('article_file_id');
        $proof = collect([$record->proofRound?->source_file_id, $record->proofRound?->author_file_id, $record->proofRound?->corrected_file_id])->filter();
        $production = $record->article->files()->whereIn('file_type', ['copy_edited_file', 'proof_file', 'publication_pdf'])->where('scan_status', 'clean')->pluck('id');

        return $accepted->merge($proof)->merge($production)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function pageOverlap(int $issueId, int $start, int $end, int $exceptArticleId): bool
    {
        return PublicationRecord::query()->where('magazine_issue_id', $issueId)->where('article_id', '!=', $exceptArticleId)
            ->whereNotNull('page_start')->whereNotNull('page_end')->where('page_start', '<=', $end)->where('page_end', '>=', $start)->exists();
    }
}
