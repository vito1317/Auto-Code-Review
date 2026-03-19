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

class MergePrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(
        public ReviewTask $task,
        public ?int $userId = null,
    ) {
        $this->onQueue('merges');
    }

    public function handle(GitHubApiService $github): void
    {
        $task = $this->task;
        $repo = $task->repository;

        $github->forUser($this->userId ?? $repo->user_id);

        $task->update([
            'merge_status' => ReviewTask::MERGE_MERGING,
            'merge_message' => 'Merging PR...',
        ]);

        try {
            $github->mergePullRequest(
                $repo->owner,
                $repo->repo,
                $task->pr_number,
                "Merge PR #{$task->pr_number}: {$task->pr_title}",
            );

            $task->update([
                'pr_status' => ReviewTask::PR_STATUS_MERGED,
                'merge_status' => ReviewTask::MERGE_MERGED,
                'merge_message' => 'PR merged successfully',
            ]);

            Log::info('MergePrJob: Merged', [
                'task' => $task->id,
                'pr' => "{$repo->owner}/{$repo->repo}#{$task->pr_number}",
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, 'not mergeable')) {
                if ($repo->auto_ai_merge) {
                    $task->update([
                        'merge_status' => null,
                        'merge_message' => null,
                        'ai_merge_status' => ReviewTask::AI_MERGE_PENDING,
                        'ai_merge_message' => 'Merge conflicts detected, starting AI merge...',
                    ]);
                    AiMergeJob::dispatch($task, $this->userId);

                    Log::info('MergePrJob: Conflicts, dispatched AI merge', ['task' => $task->id]);
                } else {
                    $task->update([
                        'merge_status' => ReviewTask::MERGE_FAILED,
                        'merge_message' => 'Merge conflicts - resolve manually',
                    ]);

                    Log::warning('MergePrJob: Merge conflicts', ['task' => $task->id]);
                }
            } else {
                $task->update([
                    'merge_status' => ReviewTask::MERGE_FAILED,
                    'merge_message' => 'Error: '.substr($msg, 0, 200),
                ]);

                Log::error('MergePrJob: Failed', ['task' => $task->id, 'error' => $msg]);

                throw $e;
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->task->update([
            'merge_status' => ReviewTask::MERGE_FAILED,
            'merge_message' => 'Job failed: '.$e->getMessage(),
        ]);
    }
}
