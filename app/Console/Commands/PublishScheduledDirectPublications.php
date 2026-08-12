<?php

namespace App\Console\Commands;

use App\Constants\DirectPublicationStatus;
use App\Models\PublicationRecord;
use App\Models\User;
use App\Services\DirectPublicationService;
use Illuminate\Console\Command;
use Throwable;

class PublishScheduledDirectPublications extends Command
{
    protected $signature = 'direct-publications:publish-scheduled {--limit=100}';

    protected $description = 'Publish due, ready, direct-publication records exactly once';

    public function handle(DirectPublicationService $service): int
    {
        $ids = PublicationRecord::query()->where('publication_mode', 'direct')
            ->where('status', DirectPublicationStatus::SCHEDULED)->where('scheduled_for', '<=', now())
            ->whereNull('publication_failed_at')->orderBy('scheduled_for')->limit((int) $this->option('limit'))->pluck('id');

        foreach ($ids as $id) {
            $record = PublicationRecord::with('article')->find($id);
            if (! $record?->article || $record->article->status !== DirectPublicationStatus::SCHEDULED) {
                continue;
            }
            $actor = User::find($record->updated_by ?: $record->created_by);
            if (! $actor) {
                $record->update(['publication_failed_at' => now(), 'publication_failure_code' => 'ACTOR_MISSING', 'publication_failure_message' => 'The scheduling actor no longer exists.']);

                continue;
            }
            try {
                $service->publish($record->article, $actor, "scheduled-publication:{$record->id}:{$record->scheduled_for?->timestamp}");
                $this->info("Published direct article {$record->article_id}.");
            } catch (Throwable $exception) {
                PublicationRecord::whereKey($id)->where('status', DirectPublicationStatus::SCHEDULED)->update([
                    'publication_failed_at' => now(), 'publication_failure_code' => 'READINESS_INVALID',
                    'publication_failure_message' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
                report($exception);
            }
        }

        return self::SUCCESS;
    }
}
