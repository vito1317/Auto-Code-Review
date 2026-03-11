<?php

namespace App\Jobs;

use App\Models\ReviewTask;
use App\Services\GitHubApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPrStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600; // 10 minutes max

    public function __construct(
        public ?int $userId = null,
    ) {
        $this->onQueue('reviews');
    }

    public function handle(GitHubApiService $github): void
    {
        $query = ReviewTask::where('pr_status', ReviewTask::PR_STATUS_OPEN)
            ->with('repository');

        if ($this->userId) {
            $query->whereHas('repository', fn ($q) => $q->where('user_id', $this->userId));
        }

        $tasks = $query->get();
        $updated = 0;
        $errors = 0;

        Log::info('Starting PR status sync', ['total_tasks' => $tasks->count()]);

        foreach ($tasks as $task) {
            $repo = $task->repository;
            try {
                $github->forUser($repo->user_id);
                $pr = $github->getPullRequest(
                    $repo->owner,
                    $repo->repo,
                    $task->pr_number,
                );

                $newStatus = match (true) {
                    ($pr['merged'] ?? false) => ReviewTask::PR_STATUS_MERGED,
                    ($pr['state'] ?? '') === 'closed' => ReviewTask::PR_STATUS_CLOSED,
                    default => ReviewTask::PR_STATUS_OPEN,
                };

                if ($task->pr_status !== $newStatus) {
                    $task->update(['pr_status' => $newStatus]);
                    $updated++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('Failed to sync PR status', [
                    'task' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Avoid hitting rate limits
            usleep(100000); // 100ms delay
        }

        Log::info('PR status sync completed', [
            'updated' => $updated,
            'errors' => $errors,
            'total' => $tasks->count(),
        ]);
    }
}
