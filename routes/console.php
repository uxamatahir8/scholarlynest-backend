<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('workflow:send-deadline-reminders')->dailyAt('08:00');
Schedule::command('workflow:auto-approve-author-final-reviews')->hourly()->withoutOverlapping();
