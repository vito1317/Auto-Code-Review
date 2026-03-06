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

    public function __construct(
        public ReviewTask $task,
    ) {
    }

    public function handle(
        GitHubApiService $github,
        CodeReviewService $reviewer,
        JulesApiService $jules,
    ): void {
        $task = $this->task;
        $repo = $task->repository;

        Log::info("Starting PR review", ['task' => $task->id, 'pr' => $task->pr_url]);

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
            $result = $reviewer->reviewDiff($diff, $repo->review_config ?? []);
            $findings = $result['findings'] ?? [];
            $summary = $result['summary'] ?? '';
            $quality = $result['overall_quality'] ?? 'acceptable';

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
            if ($reviewer->shouldAutoFix($findings)) {
                $this->triggerJulesFix($jules, $reviewer, $task, $findings);
            } else {
                $task->update(['status' => ReviewTask::STATUS_APPROVED]);
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
        $lineComments = array_filter($formattedComments, fn($c) => !empty($c['line']));

        if (empty($lineComments)) {
            return;
        }

        try {
            $headSha = $github->getPullRequestHeadSha($repo->owner, $repo->repo, $task->pr_number);

            // Post as a single review with all comments
            $reviewComments = array_map(fn($c) => [
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
                'error_message' => 'Jules auto-fix failed: ' . $e->getMessage(),
            ]);
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
        if (!empty($finding['suggestion'])) {
            $body .= "\n\n**Fix:** {$finding['suggestion']}";
        }

        return $body;
    }
}
