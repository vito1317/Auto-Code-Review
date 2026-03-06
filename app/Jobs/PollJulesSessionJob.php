<?php

namespace App\Jobs;

use App\Models\ReviewTask;
use App\Services\GitHubApiService;
use App\Services\JulesApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PollJulesSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 30;        // Poll up to 30 times
    public int $backoff = 120;     // 2 min between retries

    public function __construct(
        public ReviewTask $task,
    ) {
    }

    public function handle(JulesApiService $jules, GitHubApiService $github): void
    {
        $task = $this->task;

        if ($task->status !== ReviewTask::STATUS_FIXING || !$task->jules_session_id) {
            Log::info('Task no longer in fixing state, skipping poll', ['task' => $task->id]);
            return;
        }

        Log::info('Polling Jules session', [
            'task' => $task->id,
            'session' => $task->jules_session_id,
        ]);

        try {
            $result = $jules->getSessionOutputs($task->jules_session_id);

            if ($result['completed']) {
                // Session completed — extract PR URL
                $session = $result['session'];
                $prUrl = $jules->extractPrUrl($session);

                Log::info('Jules session completed', [
                    'task' => $task->id,
                    'pr_url' => $prUrl,
                ]);

                $task->update([
                    'status' => ReviewTask::STATUS_FIXED,
                    'jules_fix_pr_url' => $prUrl,
                ]);

                // Post a comment on the original PR linking to the fix
                if ($prUrl) {
                    $repo = $task->repository;
                    $github->createIssueComment(
                        $repo->owner,
                        $repo->repo,
                        $task->pr_number,
                        "## 🔧 Auto-Fix PR Created\n\nJules has created a fix PR: {$prUrl}\n\nThis PR addresses the critical/warning issues found during the automated review.",
                    );
                }

                return;
            }

            // Session still running — re-dispatch with delay
            Log::info('Jules session still running, will poll again', ['task' => $task->id]);
            self::dispatch($task)->delay(now()->addMinutes(2));

        } catch (\Throwable $e) {
            Log::error('Failed to poll Jules session', [
                'task' => $task->id,
                'error' => $e->getMessage(),
            ]);

            // Don't fail the task, just retry
            if ($this->attempts() >= $this->tries) {
                $task->update([
                    'status' => ReviewTask::STATUS_FAILED,
                    'error_message' => 'Jules session polling timed out: ' . $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
