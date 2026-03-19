<?php

namespace App\Console\Commands;

use App\Models\ReviewTask;
use Illuminate\Console\Command;

class CleanupStuckReviews extends Command
{
    protected $signature = 'review:cleanup-stuck {--minutes=30 : Minutes after which a reviewing task is considered stuck}';

    protected $description = 'Mark review tasks stuck in "reviewing" status as failed';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        $stuck = ReviewTask::where('status', ReviewTask::STATUS_REVIEWING)
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck review tasks found.');

            return self::SUCCESS;
        }

        foreach ($stuck as $task) {
            $task->update([
                'status' => ReviewTask::STATUS_FAILED,
                'error_message' => "Automatically reset: stuck in reviewing for over {$minutes} minutes",
            ]);

            $this->warn("Reset task #{$task->id} (PR #{$task->pr_number}) — was stuck since {$task->updated_at}");
        }

        $this->info("Reset {$stuck->count()} stuck task(s).");

        return self::SUCCESS;
    }
}
