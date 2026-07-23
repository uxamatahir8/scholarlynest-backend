<?php

namespace App\Services;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAcceptedFileSet;
use App\Models\ArticleAcceptedFileSetItem;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\Notifications\NotificationEventRecorder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class AcceptedFileSetService
{
    public function createForCurrentSubmission(Article $article, User $acceptedBy): ArticleAcceptedFileSet
    {
        return DB::transaction(function () use ($article, $acceptedBy) {
            Article::whereKey($article->id)->lockForUpdate()->firstOrFail();

            $version = ArticleVersion::query()
                ->where('article_id', $article->id)
                ->whereIn('status_snapshot', [ArticleStatus::SUBMITTED, ArticleStatus::RESUBMITTED])
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if (! $version) {
                $this->fail('The article cannot be accepted because it has no submitted manuscript version.');
            }

            $manuscript = app(PrimaryManuscriptService::class)->validateAuthoritative($article, $version);

            $supporting = ArticleFile::query()
                ->where('article_id', $article->id)
                ->where('article_version_id', $version->id)
                ->whereIn('file_type', [ArticleFile::ADDITIONAL_MANUSCRIPT_FILE, ArticleFile::SUPPLEMENTARY])
                ->where('scan_status', 'clean')
                ->whereNull('assignment_type')
                ->orderBy('id')
                ->get()
                ->values();

            $supersededIds = ArticleAcceptedFileSet::query()
                ->where('article_id', $article->id)
                ->whereNull('superseded_at')
                ->pluck('id');
            ArticleAcceptedFileSet::query()->whereIn('id', $supersededIds)->update(['superseded_at' => now()]);

            $acceptedAt = now();
            $set = ArticleAcceptedFileSet::create([
                'article_id' => $article->id,
                'article_version_id' => $version->id,
                'accepted_by' => $acceptedBy->id,
                'accepted_at' => $acceptedAt,
                'selection_policy' => ArticleAcceptedFileSet::POLICY_VERSION_LOCAL,
            ]);

            $this->addItem($set, $manuscript, ArticleAcceptedFileSetItem::ROLE_MANUSCRIPT);
            $supporting->each(fn (ArticleFile $file) => $this->addItem(
                $set,
                $file,
                $file->file_type === ArticleFile::SUPPLEMENTARY
                    ? ArticleAcceptedFileSetItem::ROLE_SUPPLEMENTARY
                    : ArticleAcceptedFileSetItem::ROLE_ADDITIONAL
            ));

            $version->update([
                'accepted_at' => $acceptedAt,
                'accepted_by' => $acceptedBy->id,
            ]);

            foreach ($supersededIds as $supersededId) {
                app(NotificationEventRecorder::class)->record(
                    'accepted_file_set.superseded', $article, $acceptedBy,
                    ['accepted_file_set_id' => $supersededId], 'accepted_file_set', (int) $supersededId,
                    deduplicationKey: "accepted-file-set:{$supersededId}:superseded"
                );
            }
            app(NotificationEventRecorder::class)->record(
                'accepted_file_set.created', $article, $acceptedBy,
                ['accepted_file_set_id' => $set->id, 'article_version_id' => $version->id],
                'accepted_file_set', $set->id,
                deduplicationKey: "accepted-file-set:{$set->id}:created"
            );

            return $set->fresh(['version', 'accepter:id,name', 'items.file.uploader:id,name', 'items.sourceVersion']);
        });
    }

    private function addItem(ArticleAcceptedFileSet $set, ArticleFile $file, string $role): void
    {
        $set->items()->create([
            'article_file_id' => $file->id,
            'source_version_id' => $file->article_version_id,
            'accepted_role' => $role,
        ]);
    }

    private function fail(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], 422));
    }
}
