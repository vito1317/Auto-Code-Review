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
        $this->onQueue('reviews');
    }

    public function handle(JulesApiService $jules, GitHubApiService $github): void
    {
        $task = $this->task;

        if ($task->status !== ReviewTask::STATUS_FIXING || ! $task->jules_session_id) {
            Log::info('Task no longer in fixing state, skipping poll', ['task' => $task->id]);

            return;
        }

        // Set per-user context for API services
        $userId = $task->repository->user_id;
        $github->forUser($userId);
        $jules->forUser($userId);

        Log::info('Polling Jules session', [
            'task' => $task->id,
            'session' => $task->jules_session_id,
        ]);

        try {
            $result = $jules->getSessionOutputs($task->jules_session_id);

            if ($result['completed']) {
                $session = $result['session'];
                $sessionState = $result['state'] ?? '';

                // If Jules is waiting for user to publish PR, auto-submit it
                if ($sessionState === 'AWAITING_USER_FEEDBACK') {
                    Log::info('Jules session awaiting feedback, auto-submitting PR', ['task' => $task->id]);

                    try {
                        $jules->submitPullRequest($task->jules_session_id);
                        Log::info('Jules PR auto-submitted, re-dispatching poll', ['task' => $task->id]);
                    } catch (\Throwable $e) {
                        Log::warning('Jules submitPullRequest failed, trying sendMessage', [
                            'task' => $task->id,
                            'error' => $e->getMessage(),
                        ]);

                        // Fallback: try sendMessage to accept
                        try {
                            $jules->sendMessage($task->jules_session_id, 'Please publish the pull request.');
                        } catch (\Throwable $e2) {
                            Log::warning('Jules sendMessage fallback also failed', [
                                'task' => $task->id,
                                'error' => $e2->getMessage(),
                            ]);
                        }
                    }

                    // Re-dispatch to check again in 30 seconds
                    self::dispatch($this->task)->delay(now()->addSeconds(30));

                    return;
                }

                // If Jules session FAILED, mark the task as failed
                if ($sessionState === 'FAILED') {
                    Log::warning('Jules session failed', ['task' => $task->id]);
                    $task->update([
                        'status' => ReviewTask::STATUS_FAILED,
                        'error_message' => 'Jules session failed to complete.',
                    ]);

                    return;
                }

                // Session completed — extract PR URL
                $prUrl = $jules->extractPrUrl($session);
                $repo = $task->repository;

                // If Jules didn't auto-create a PR, create one via GitHub API
                if (! $prUrl) {
                    $prUrl = $this->createPrFromJulesSession($jules, $github, $session, $task);
                }

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
                    $github->createIssueComment(
                        $repo->owner,
                        $repo->repo,
                        $task->pr_number,
                        "## 🔧 Auto-Fix PR Created\n\nJules has created a fix PR: {$prUrl}\n\nThis PR addresses the critical/warning issues found during the automated review.\n\n**This PR will be closed automatically.** Please review the fix PR instead.",
                    );

                    // Close the original PR since it's been superseded by the fix PR
                    try {
                        $github->closePullRequest(
                            $repo->owner,
                            $repo->repo,
                            $task->pr_number,
                        );

                        Log::info('Original PR closed after Jules fix PR created', [
                            'task' => $task->id,
                            'original_pr' => $task->pr_number,
                            'fix_pr' => $prUrl,
                        ]);
                        $task->update(['pr_status' => ReviewTask::PR_STATUS_CLOSED]);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to close original PR after Jules fix', [
                            'task' => $task->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Auto-merge the fix PR if enabled for this repository
                if ($repo->auto_merge && $prUrl) {
                    // Extract the fix PR number from the URL
                    if (preg_match('/\/pull\/(\d+)/', $prUrl, $matches)) {
                        $fixPrNumber = (int) $matches[1];
                        try {
                            $github->mergePullRequest(
                                $repo->owner,
                                $repo->repo,
                                $fixPrNumber,
                                "Auto-merge fix PR #{$fixPrNumber} for #{$task->pr_number}: {$task->pr_title}",
                            );

                            Log::info('Fix PR auto-merged', [
                                'task' => $task->id,
                                'fix_pr' => $fixPrNumber,
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('Auto-merge failed for fix PR', [
                                'task' => $task->id,
                                'fix_pr' => $fixPrNumber,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
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
                    'error_message' => 'Jules session polling timed out: '.$e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Create a PR on GitHub from Jules' completed session when AUTO_CREATE_PR didn't produce one.
     */
    private function createPrFromJulesSession(
        JulesApiService $jules,
        GitHubApiService $github,
        array $session,
        ReviewTask $task,
    ): ?string {
        $repo = $task->repository;

        try {
            $branchName = $jules->extractBranchName($session);

            if (! $branchName) {
                Log::warning('Could not extract branch name from Jules session', ['task' => $task->id]);

                return null;
            }

            Log::info('Creating PR from Jules branch', [
                'task' => $task->id,
                'branch' => $branchName,
                'base' => $repo->default_branch,
            ]);

            $pr = $github->createPullRequest(
                owner: $repo->owner,
                repo: $repo->repo,
                title: "🔧 Auto-Fix: Review issues in PR #{$task->pr_number}",
                head: $branchName,
                base: $repo->default_branch,
                body: "## Auto-Fix PR\n\nThis PR was automatically created to fix issues found during the code review of PR #{$task->pr_number}.\n\n**Original PR:** {$task->pr_url}\n**Review Task:** #{$task->id}\n\n---\n*Generated by Auto Code Review*",
            );

            $prUrl = $pr['html_url'] ?? null;

            Log::info('PR created successfully from Jules session', [
                'task' => $task->id,
                'pr_url' => $prUrl,
            ]);

            return $prUrl;

        } catch (\Throwable $e) {
            Log::warning('Failed to create PR from Jules session', [
                'task' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
