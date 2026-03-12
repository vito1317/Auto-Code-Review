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

    public int $tries = 5;

    public int $backoff = 120; // wait 120 seconds between retries (rate limit recovery)

    public int $timeout = 900; // 15 minutes (streaming can be slow for large files)

    public function __construct(
        public ReviewTask $task,
        public ?int $userId = null,
    ) {
        $this->onQueue('merges');
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
                onProgress: function (string $message) use ($task) {
                    $task->update(['ai_merge_message' => $message]);
                },
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
                $msg = $result['message'];
                $isRetryable = str_contains($msg, 'rate limit')
                    || str_contains($msg, 'timed out')
                    || str_contains($msg, '403')
                    || str_contains($msg, '502')
                    || str_contains($msg, '503')
                    || str_contains($msg, 'merge failed')
                    || str_contains($msg, 'merge still failed');

                if ($isRetryable) {
                    $attempt = $this->attempts();
                    $task->update([
                        'ai_merge_status' => ReviewTask::AI_MERGE_PROCESSING,
                        'ai_merge_message' => "Retry {$attempt}/{$this->tries}: {$msg} — cooling down 60s...",
                    ]);

                    Log::warning('AiMergeJob: Retryable failure', [
                        'task' => $task->id,
                        'attempt' => $attempt,
                        'message' => $msg,
                    ]);

                    throw new \RuntimeException("AI merge retryable: {$msg}");
                }

                // Permanent failure (e.g., "could not resolve any files")
                $task->update([
                    'ai_merge_status' => ReviewTask::AI_MERGE_FAILED,
                    'ai_merge_message' => $msg,
                ]);

                Log::warning('AiMergeJob: Permanent failure', [
                    'task' => $task->id,
                    'message' => $msg,
                ]);
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $isRetryable = str_contains($msg, 'rate limit')
                || str_contains($msg, 'timed out')
                || str_contains($msg, '403')
                || str_contains($msg, '502')
                || str_contains($msg, '503')
                || str_contains($msg, 'retryable');

            if ($isRetryable) {
                $attempt = $this->attempts();
                $task->update([
                    'ai_merge_status' => ReviewTask::AI_MERGE_PROCESSING,
                    'ai_merge_message' => "Retry {$attempt}/{$this->tries}: {$msg} — cooling down 60s...",
                ]);
                throw $e; // Let Laravel retry with backoff
            }

            $task->update([
                'ai_merge_status' => ReviewTask::AI_MERGE_FAILED,
                'ai_merge_message' => 'Exception: '.$msg,
            ]);

            Log::error('AiMergeJob: Exception', [
                'task' => $task->id,
                'error' => $msg,
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
