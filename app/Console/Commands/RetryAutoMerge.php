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
            ->where('pr_status', ReviewTask::PR_STATUS_OPEN) // Only if it's currently open
            ->where('created_at', '>=', now()->subDays(7))   // Limit to recent PRs
            ->whereHas('repository', fn ($q) => $q->where('auto_merge', true))
            ->with('repository')
            ->limit(30) // Max 30 merges per cycle to avoid API rate limits
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

                Log::warning('RetryAutoMerge: merge failed', [
                    'task' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Merged {$merged}/{$tasks->count()} PRs.");

        return self::SUCCESS;
    }
}
