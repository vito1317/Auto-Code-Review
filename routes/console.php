<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll Jules sessions every 5 minutes
Schedule::command('jules:poll-sessions')->everyFiveMinutes();

// Poll for new open PRs every 10 minutes (safety net for missed webhooks)
Schedule::command('review:poll')->everyTenMinutes();

// Retry auto-merge for approved tasks every 10 minutes
Schedule::command('review:auto-merge')->everyTenMinutes();
