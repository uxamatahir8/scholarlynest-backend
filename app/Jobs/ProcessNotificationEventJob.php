<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationEventProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessNotificationEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $notificationEventId) {}

    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(NotificationEventProjector $projector): void
    {
        $projector->project($this->notificationEventId);
    }
}
