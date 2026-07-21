<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNotificationEventJob;
use App\Models\NotificationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecoverNotificationOutbox extends Command
{
    protected $signature = 'notifications:recover-outbox {--chunk= : Maximum rows to dispatch}';

    protected $description = 'Redispatch due notification outbox events whose original projector job was lost.';

    public function handle(): int
    {
        if (! config('notification_system.features.enabled', true)) {
            $this->info('Notifications are disabled.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('notifications:recover-outbox', 55);
        if (! $lock->get()) {
            $this->info('Another notification outbox recovery scan is running.');

            return self::SUCCESS;
        }

        try {
            $maxAttempts = (int) config('notification_system.outbox.max_attempts', 5);
            $staleBefore = now()->subMinutes((int) config('notification_system.outbox.stale_after_minutes', 10));
            $chunk = max(1, min(1000, (int) ($this->option('chunk') ?: config('notification_system.outbox.chunk_size', 100))));

            NotificationEvent::query()->whereNull('processed_at')->whereNull('permanently_failed_at')
                ->where('attempt_count', '>=', $maxAttempts)
                ->update([
                    'permanently_failed_at' => now(),
                    'processing_at' => null,
                    'failure_code' => 'max_attempts_exceeded',
                    'last_error' => 'Projection permanently failed after the configured retry limit.',
                    'updated_at' => now(),
                ]);

            $ids = NotificationEvent::query()->whereNull('processed_at')->whereNull('permanently_failed_at')
                ->where('attempt_count', '<', $maxAttempts)
                ->where('available_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('processing_at')->orWhere('processing_at', '<=', $staleBefore))
                ->orderBy('id')->limit($chunk)->pluck('id');

            foreach ($ids as $id) {
                ProcessNotificationEventJob::dispatch((int) $id)->onQueue('default');
            }

            $this->info('Notification outbox events redispatched: '.$ids->count());

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
