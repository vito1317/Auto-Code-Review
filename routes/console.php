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

// Cleanup review tasks stuck in "reviewing" status (safety net for killed workers)
Schedule::command('review:cleanup-stuck')->everyFifteenMinutes();

// Re-dispatch any pending tasks that lost their queue jobs
Schedule::command('review:retry-pending')->everyFifteenMinutes();

// Sync PR statuses from GitHub (detect merged/closed PRs)
Schedule::call(function () {
    \App\Jobs\SyncPrStatusJob::dispatch();
})->everyThirtyMinutes()->name('sync-pr-status');

// Ensure queue workers are always running for reviews and merges
Schedule::call(function () {
    $queues = [
        'reviews' => ['desired' => 1, 'timeout' => 300, 'tries' => 1],
        'merges'  => ['desired' => 1, 'timeout' => 900, 'tries' => 5],
    ];

    foreach ($queues as $queue => $config) {
        $workerCount = (int) trim(shell_exec("ps aux | grep '[q]ueue:work' | grep '--queue={$queue}' | wc -l") ?? '0');
        $toStart = $config['desired'] - $workerCount;

        for ($i = 0; $i < $toStart; $i++) {
            $logFile = storage_path("logs/worker-{$queue}.log");
            $timeout = $config['timeout'];
            $tries = $config['tries'];
            exec('nohup php ' . base_path('artisan') . " queue:work --sleep=5 --tries={$tries} --timeout={$timeout} --max-time=7200 --queue={$queue} >> {$logFile} 2>&1 &");
        }

        if ($toStart > 0) {
            \Illuminate\Support\Facades\Log::info("Started {$toStart} {$queue} queue workers (had {$workerCount}/{$config['desired']})");
        }
    }
})->everyMinute()->name('ensure-queue-workers');

