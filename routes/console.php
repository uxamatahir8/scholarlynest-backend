<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('workflow:send-deadline-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
Schedule::command('notifications:send-digests')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('notifications:recover-outbox')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('direct-publications:publish-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();
