<?php

namespace App\Jobs;

use App\Models\ReviewComment;
use App\Models\ReviewTask;
use App\Services\CodeReviewService;
use App\Services\GitHubApiService;
use App\Services\JulesApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReviewPrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        public ReviewTask $task,
    ) {
        $this->onQueue('reviews');
    }

    /**
     * Handle job failure — reset task status so it doesn't stay stuck in "reviewing".
     */
    public function failed(?\Throwable $exception): void
    {
        $this->task->update([
            'status' => ReviewTask::STATUS_FAILED,
            'error_message' => 'Job failed: '.($exception?->getMessage() ?? 'Unknown error'),
        ]);
    }

    public function handle(
        GitHubApiService $github,
        CodeReviewService $reviewer,
        JulesApiService $jules,
    ): void {
        $task = $this->task;
        $repo = $task->repository;
        $userId = $repo->user_id;

        // Set per-user context for API services
        $github->forUser($userId);
        $jules->forUser($userId);

        Log::info('Starting PR review', ['task' => $task->id, 'pr' => $task->pr_url]);

        try {
            // 1. Update status to reviewing
            $task->update(['status' => ReviewTask::STATUS_REVIEWING]);

            // 2. Fetch PR diff from GitHub
            $diff = $github->getPullRequestDiff($repo->owner, $repo->repo, $task->pr_number);
            $task->update(['diff_content' => $diff]);

            if (empty(trim($diff))) {
                $task->update([
                    'status' => ReviewTask::STATUS_APPROVED,
                    'review_summary' => 'No changes detected in this PR.',
                ]);

                return;
            }

            // 3. Run AI-powered code review
            $result = $reviewer->reviewDiff($diff, $repo->review_config ?? [], $repo->user_id);
            $findings = $result['findings'] ?? [];
            $summary = $result['summary'] ?? '';
            $quality = $result['overall_quality'] ?? 'acceptable';
            $rawOutput = $result['raw_output'] ?? null;

            // If AI returned "unknown" quality, the response parsing failed — retry
            if ($quality === 'unknown') {
                Log::warning('AI review returned unknown quality, will retry', ['task' => $task->id]);
                throw new \RuntimeException('AI review response could not be parsed (unknown quality), retrying...');
            }

            // Save raw AI output for debugging
            if ($rawOutput) {
                $task->update(['ai_raw_output' => $rawOutput]);
            }

            // 4. Save findings as ReviewComment records
            foreach ($findings as $finding) {
                ReviewComment::create([
                    'review_task_id' => $task->id,
                    'file_path' => $finding['file_path'] ?? 'unknown',
                    'line_number' => $finding['line_number'] ?? null,
                    'severity' => $finding['severity'] ?? 'info',
                    'category' => $finding['category'] ?? 'general',
                    'body' => $this->formatCommentBody($finding),
                ]);
            }

            // 5. Post review summary comment on GitHub PR
            $reviewSummary = $reviewer->generateReviewSummary($findings, $quality);
            $github->createIssueComment(
                $repo->owner,
                $repo->repo,
                $task->pr_number,
                $reviewSummary,
            );

            // 6. Post line-specific comments if we have findings with line numbers
            $this->postLineComments($github, $reviewer, $repo, $task, $findings);

            $task->update([
                'status' => ReviewTask::STATUS_COMMENTED,
                'review_summary' => $summary,
            ]);

            // 7. If critical/warning issues found, trigger Jules auto-fix
            //    (skip auto-fix when auto_merge is enabled — just approve & merge)
            if ($reviewer->shouldAutoFix($findings) && ! $repo->auto_merge) {
                $this->triggerJulesFix($jules, $reviewer, $task, $findings);
            } else {
                $task->update(['status' => ReviewTask::STATUS_APPROVED]);

                // 8. Auto-merge if enabled for this repository
                if ($repo->auto_merge) {
                    $this->autoMergePr($github, $repo, $task);
                }
            }

        } catch (\Throwable $e) {
            Log::error('PR review failed', [
                'task' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $task->update([
                'status' => ReviewTask::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Post line-specific review comments on the GitHub PR.
     */
    private function postLineComments(
        GitHubApiService $github,
        CodeReviewService $reviewer,
        $repo,
        ReviewTask $task,
        array $findings,
    ): void {
        $formattedComments = $reviewer->formatReviewAsComments($findings);
        $lineComments = array_filter($formattedComments, fn ($c) => ! empty($c['line']));

        if (empty($lineComments)) {
            return;
        }

        try {
            $headSha = $github->getPullRequestHeadSha($repo->owner, $repo->repo, $task->pr_number);

            // Post as a single review with all comments
            $reviewComments = array_map(fn ($c) => [
                'path' => $c['path'],
                'line' => $c['line'],
                'body' => $c['body'],
                'side' => 'RIGHT',
            ], array_values($lineComments));

            $github->createReview(
                $repo->owner,
                $repo->repo,
                $task->pr_number,
                'COMMENT',
                'Detailed findings from automated code review:',
                $reviewComments,
            );
        } catch (\Throwable $e) {
            // Line comments failing shouldn't block the whole review
            Log::warning('Failed to post line comments', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Trigger Jules to auto-fix the issues found.
     */
    private function triggerJulesFix(
        JulesApiService $jules,
        CodeReviewService $reviewer,
        ReviewTask $task,
        array $findings,
    ): void {
        $repo = $task->repository;

        $prompt = $reviewer->buildJulesFixPrompt($findings, $task->pr_number);

        try {
            $session = $jules->createSession(
                source: $repo->getJulesSourceIdentifier(),
                prompt: $prompt,
                branch: $repo->default_branch,
                automationMode: 'AUTO_CREATE_PR',
                title: "Fix: Review issues in PR #{$task->pr_number}",
            );

            $sessionId = $session['id'] ?? $session['name'] ?? null;

            $task->update([
                'status' => ReviewTask::STATUS_FIXING,
                'jules_session_id' => $sessionId,
            ]);

            Log::info('Jules fix session created', [
                'task' => $task->id,
                'session' => $sessionId,
            ]);

            // Dispatch poller to check session completion
            PollJulesSessionJob::dispatch($task)->delay(now()->addMinutes(2));

        } catch (\Throwable $e) {
            Log::error('Failed to create Jules fix session', [
                'task' => $task->id,
                'error' => $e->getMessage(),
            ]);

            $task->update([
                'status' => ReviewTask::STATUS_COMMENTED,
                'error_message' => 'Jules auto-fix failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-merge the PR when AI review approves it.
     */
    private function autoMergePr(
        GitHubApiService $github,
        $repo,
        ReviewTask $task,
    ): void {
        // Try to post an APPROVE review (may fail if PR author == token owner)
        try {
            $github->createReview(
                $repo->owner,
                $repo->repo,
                $task->pr_number,
                'APPROVE',
                '✅ **AI Auto-Merge**: This PR has passed automated code review and will be merged automatically.',
            );
        } catch (\Throwable $e) {
            Log::info('Could not approve PR (likely self-PR), proceeding with merge', [
                'task' => $task->id,
                'error' => $e->getMessage(),
            ]);

            // Post a comment instead since we can't approve
            try {
                $github->createIssueComment(
                    $repo->owner,
                    $repo->repo,
                    $task->pr_number,
                    '✅ **AI Auto-Merge**: This PR has passed automated code review and will be merged automatically.',
                );
            } catch (\Throwable) {
                // Ignore comment failure
            }
        }

        // Try to merge the PR
        try {
            $github->mergePullRequest(
                $repo->owner,
                $repo->repo,
                $task->pr_number,
                "Auto-merge PR #{$task->pr_number}: {$task->pr_title}",
            );

            Log::info('PR auto-merged successfully', [
                'task' => $task->id,
                'pr' => "{$repo->owner}/{$repo->repo}#{$task->pr_number}",
            ]);
        } catch (\Throwable $e) {
            Log::warning('Auto-merge failed, PR remains approved but unmerged', [
                'task' => $task->id,
                'error' => $e->getMessage(),
            ]);

            // If merge conflicts and auto_ai_merge is enabled, dispatch AI merge
            if ($repo->auto_ai_merge && str_contains($e->getMessage(), 'not mergeable')) {
                $task->update([
                    'ai_merge_status' => ReviewTask::AI_MERGE_PENDING,
                    'ai_merge_message' => 'Auto-triggered: merge conflict detected',
                ]);
                AiMergeJob::dispatch($task, $repo->user_id);

                Log::info('Auto AI merge triggered for conflicting PR', [
                    'task' => $task->id,
                    'pr' => "{$repo->owner}/{$repo->repo}#{$task->pr_number}",
                ]);

                try {
                    $github->createIssueComment(
                        $repo->owner,
                        $repo->repo,
                        $task->pr_number,
                        "⚠️ **Auto-Merge Failed**: Merge conflicts detected.\n\n🤖 **AI Merge** has been automatically triggered to resolve conflicts.",
                    );
                } catch (\Throwable) {
                    // Silently ignore comment failure
                }

                return;
            }

            // Post a comment letting them know auto-merge failed
            try {
                $github->createIssueComment(
                    $repo->owner,
                    $repo->repo,
                    $task->pr_number,
                    "⚠️ **Auto-Merge Failed**: This PR passed AI review but could not be merged automatically.\n\nReason: {$e->getMessage()}\n\nPlease merge manually.",
                );
            } catch (\Throwable) {
                // Silently ignore comment failure
            }
        }
    }

    /**
     * Format a finding into a comment body string.
     */
    private function formatCommentBody(array $finding): string
    {
        $icon = match ($finding['severity'] ?? 'info') {
            'critical' => '🚨', 'warning' => '⚠️', 'suggestion' => '💡', default => 'ℹ️',
        };

        $body = "{$icon} **{$finding['title']}**\n\n{$finding['body']}";
        if (! empty($finding['suggestion'])) {
            $body .= "\n\n**Fix:** {$finding['suggestion']}";
        }

        return $body;
    }
}
