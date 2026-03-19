<?php

namespace App\Console\Commands;

use App\Jobs\ReviewPrJob;
use App\Models\ReviewTask;
use Illuminate\Console\Command;

class RetryPendingTasks extends Command
{
    protected $signature = 'review:retry-pending';

    protected $description = 'Re-dispatch all stuck pending review tasks';

    public function handle(): int
    {
        $tasks = ReviewTask::where('status', 'pending')->get();

        if ($tasks->isEmpty()) {
            $this->info('No pending tasks found.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            ReviewPrJob::dispatch($task);
            $this->info("Re-dispatched task #{$task->id} (PR #{$task->pr_number})");
        }

        $this->info("Done! {$tasks->count()} task(s) re-dispatched.");

        return self::SUCCESS;
    }
}
