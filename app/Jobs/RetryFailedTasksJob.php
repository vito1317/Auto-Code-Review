<?php

namespace App\Jobs;

use App\Models\ReviewTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryFailedTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
    ) {}

    public function handle(): void
    {
        $tasks = ReviewTask::where('status', ReviewTask::STATUS_FAILED)
            ->where('pr_status', ReviewTask::PR_STATUS_OPEN)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereHas('repository', fn ($q) => $q->where('user_id', $this->userId))
            ->get();

        $count = 0;
        foreach ($tasks as $task) {
            $task->update([
                'status' => ReviewTask::STATUS_PENDING,
                'error_message' => null,
                'iteration' => $task->iteration + 1,
            ]);
            ReviewPrJob::dispatch($task);
            $count++;
        }

        Log::info("RetryFailedTasksJob: retried {$count} tasks for user {$this->userId}");
    }
}
