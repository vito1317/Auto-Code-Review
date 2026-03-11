<?php

namespace App\Jobs;

use App\Models\ReviewTask;
use App\Services\AiMergeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AiMergeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60; // wait 60 seconds between retries (rate limit recovery)

    public int $timeout = 300; // 5 minutes

    public function __construct(
        public ReviewTask $task,
        public ?int $userId = null,
    ) {
        $this->onQueue('reviews');
    }

    public function handle(AiMergeService $aiMerge): void
    {
        $task = $this->task;
        $repo = $task->repository;

        // Set per-user context
        $aiMerge->forUser($this->userId ?? $repo->user_id);

        // Mark as processing
        $task->update([
            'ai_merge_status' => ReviewTask::AI_MERGE_PROCESSING,
            'ai_merge_message' => 'AI merge started...',
        ]);

        Log::info('AiMergeJob: Starting', [
            'task' => $task->id,
            'pr' => "{$repo->owner}/{$repo->repo}#{$task->pr_number}",
        ]);

        try {
            $result = $aiMerge->resolveAndMerge(
                $repo->owner,
                $repo->repo,
                $task->pr_number,
                $this->userId,
            );

            if ($result['success']) {
                $task->update([
                    'pr_status' => ReviewTask::PR_STATUS_MERGED,
                    'ai_merge_status' => ReviewTask::AI_MERGE_RESOLVED,
                    'ai_merge_message' => $result['message'],
                ]);

                Log::info('AiMergeJob: Success', [
                    'task' => $task->id,
                    'message' => $result['message'],
                ]);
            } else {
                $task->update([
                    'ai_merge_status' => ReviewTask::AI_MERGE_FAILED,
                    'ai_merge_message' => $result['message'],
                ]);

                Log::warning('AiMergeJob: Failed', [
                    'task' => $task->id,
                    'message' => $result['message'],
                ]);
            }
        } catch (\Throwable $e) {
            $task->update([
                'ai_merge_status' => ReviewTask::AI_MERGE_FAILED,
                'ai_merge_message' => 'Exception: '.$e->getMessage(),
            ]);

            Log::error('AiMergeJob: Exception', [
                'task' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(\Throwable $e): void
    {
        $this->task->update([
            'ai_merge_status' => ReviewTask::AI_MERGE_FAILED,
            'ai_merge_message' => 'Job failed: '.$e->getMessage(),
        ]);

        Log::error('AiMergeJob: Permanently failed', [
            'task' => $this->task->id,
            'error' => $e->getMessage(),
        ]);
    }
}
