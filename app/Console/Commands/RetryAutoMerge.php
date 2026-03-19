<?php

namespace App\Console\Commands;

use App\Models\ReviewTask;
use App\Services\GitHubApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryAutoMerge extends Command
{
    protected $signature = 'review:auto-merge';

    protected $description = 'Retry auto-merge for approved tasks in repos with auto_merge enabled';

    public function handle(GitHubApiService $github): int
    {
        $tasks = ReviewTask::whereIn('status', [ReviewTask::STATUS_APPROVED, ReviewTask::STATUS_FIXED])
            ->whereHas('repository', fn ($q) => $q->where('auto_merge', true))
            ->with('repository')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No approved tasks pending auto-merge.');

            return self::SUCCESS;
        }

        $merged = 0;

        foreach ($tasks as $task) {
            $repo = $task->repository;
            $this->info("Attempting merge: {$repo->owner}/{$repo->repo}#{$task->pr_number}");

            try {
                $github->mergePullRequest(
                    $repo->owner,
                    $repo->repo,
                    $task->pr_number,
                    "Auto-merge PR #{$task->pr_number}: {$task->pr_title}",
                );

                $this->info('  ✅ Merged successfully');
                $merged++;

                Log::info('Retry auto-merge: PR merged', [
                    'task' => $task->id,
                    'pr' => "{$repo->owner}/{$repo->repo}#{$task->pr_number}",
                ]);
            } catch (\Throwable $e) {
                $this->warn("  ❌ Failed: {$e->getMessage()}");

                // Post comment about failure
                try {
                    $github->createIssueComment(
                        $repo->owner,
                        $repo->repo,
                        $task->pr_number,
                        "⚠️ **Auto-Merge Failed**: {$e->getMessage()}\n\nPlease resolve conflicts and merge manually.",
                    );
                } catch (\Throwable) {
                    // Ignore
                }
            }
        }

        $this->info("Done. Merged {$merged}/{$tasks->count()} PRs.");

        return self::SUCCESS;
    }
}
