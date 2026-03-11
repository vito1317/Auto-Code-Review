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

// Ensure merges queue workers are always running (2 workers)
Schedule::call(function () {
    $workerCount = (int) trim(shell_exec("ps aux | grep 'queue=merges' | grep -v grep | wc -l") ?? '0');
    $desired = 2;
    $toStart = $desired - $workerCount;

    for ($i = 0; $i < $toStart; $i++) {
        $logFile = storage_path('logs/worker-merges-'.($workerCount + $i + 1).'.log');
        exec('nohup php '.base_path('artisan')." queue:work --sleep=3 --tries=3 --timeout=300 --max-time=3600 --queue=merges > {$logFile} 2>&1 &");
    }

    if ($toStart > 0) {
        \Illuminate\Support\Facades\Log::info("Started {$toStart} merges queue workers (had {$workerCount}/{$desired})");
    }
})->everyMinute()->name('ensure-merges-workers');
