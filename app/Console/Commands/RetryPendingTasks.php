<?php

namespace App\Console\Commands;

use App\Jobs\ReviewPrJob;
use App\Models\ReviewTask;
use Illuminate\Console\Command;

class RetryPendingTasks extends Command
{
    protected $signature = 'review:retry-pending {--limit=20 : Max tasks to re-dispatch per run}';

    protected $description = 'Re-dispatch stuck pending review tasks (limited batch to avoid rate limit)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        // Only retry tasks created in the last 3 days to avoid retrying ancient stale tasks
        $tasks = ReviewTask::where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No recent pending tasks found.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            ReviewPrJob::dispatch($task);
            $this->info("Re-dispatched task #{$task->id} (PR #{$task->pr_number})");
        }

        $this->info("Done! {$tasks->count()} task(s) re-dispatched (limit: {$limit}).");

        return self::SUCCESS;
    }
}
