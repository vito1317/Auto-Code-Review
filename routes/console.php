<?php

use App\Jobs\SyncPrStatusJob;
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

// Cleanup review tasks stuck in "reviewing" status (safety net for killed workers)
Schedule::command('review:cleanup-stuck')->everyFifteenMinutes();

// Re-dispatch any pending tasks that lost their queue jobs
Schedule::command('review:retry-pending')->everyFifteenMinutes();

// Sync PR statuses from GitHub (detect merged/closed PRs)
Schedule::call(function () {
    SyncPrStatusJob::dispatch();
})->everyThirtyMinutes()->name('sync-pr-status');

// Queue workers are managed by Supervisor (see /etc/supervisor/conf.d/auto-code-review.conf)
