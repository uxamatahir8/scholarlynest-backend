<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationPresenter;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests {--force : Ignore the recipient-local 08:00 delivery window}';

    protected $description = 'Queue idempotent daily and weekly notification digest emails in each recipient timezone.';

    public function handle(NotificationPresenter $presenter, NotificationService $email): int
    {
        if (! config('notification_system.features.enabled', true) || ! config('notification_system.features.digests', true)) {
            $this->info('Notification digests are disabled.');

            return self::SUCCESS;
        }
        $queued = 0;
        $userIds = UserNotification::query()
            ->where('email_mode', 'digest')
            ->whereNull('digest_sent_at')
            ->distinct()
            ->orderBy('recipient_user_id')
            ->pluck('recipient_user_id');

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (! $user || ! $user->email) {
                continue;
            }

            $timezone = $user->notificationPreferences()->whereNotNull('timezone')->value('timezone') ?: 'UTC';
            $localNow = now()->timezone($timezone);
            if (! $this->option('force') && (int) $localNow->format('G') !== 8) {
                continue;
            }

            foreach (['daily', 'weekly'] as $frequency) {
                if ($frequency === 'weekly' && ! $localNow->isMonday()) {
                    continue;
                }
                $items = $this->eligibleItems($user, $frequency, $presenter);
                if ($items->isEmpty()) {
                    continue;
                }

                $window = $frequency === 'daily'
                    ? $localNow->format('Y-m-d')
                    : $localNow->format('o-\WW');
                $dedupe = hash('sha256', "digest|{$frequency}|{$user->id}|{$timezone}|{$window}");
                $body = $items->map(fn (array $item) => $item['title'].' — '.$item['body'])->all();
                $body[] = 'Open your ScholarlyNest notification center to review these updates.';

                $email->send(
                    $user->email,
                    'Your ScholarlyNest '.ucfirst($frequency).' Digest',
                    'Dear '.($user->name ?: 'ScholarlyNest user').',',
                    $body,
                    null,
                    'low',
                    $user->id,
                    context: [
                        'purpose' => "notification.digest.{$frequency}",
                        'privacy_variant' => 'digest',
                        'deduplication_key' => $dedupe,
                    ],
                );

                UserNotification::query()->whereKey($items->pluck('id')->all())->whereNull('digest_sent_at')->update([
                    'digest_sent_at' => now(),
                    'email_queued_at' => now(),
                    'updated_at' => now(),
                ]);
                $queued++;
            }
        }

        $this->info("Notification digests queued: {$queued}");

        return self::SUCCESS;
    }

    private function eligibleItems(User $user, string $frequency, NotificationPresenter $presenter)
    {
        return UserNotification::query()
            ->with(['article.magazine'])
            ->where('recipient_user_id', $user->id)
            ->where('email_mode', 'digest')
            ->where('digest_frequency', $frequency)
            ->whereNull('digest_sent_at')
            ->where(fn ($query) => $query->where('action_status', 'none')->orWhere(function ($action) {
                $action->where('action_status', 'pending')
                    ->where(fn ($expiry) => $expiry->whereNull('action_expires_at')->orWhere('action_expires_at', '>', now()));
            }))
            ->oldest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (UserNotification $notification) => ['id' => $notification->id] + $presenter->present($notification, $user))
            ->reject(fn (array $item) => $item['unavailable'])
            ->values();
    }
}
