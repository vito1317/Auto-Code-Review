<?php

namespace App\Console\Commands;

use App\Jobs\ReviewPrJob;
use App\Models\Repository;
use App\Models\ReviewTask;
use App\Services\GitHubApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollNewPullRequests extends Command
{
    protected $signature = 'review:poll';

    protected $description = 'Poll all active repositories for new open PRs and create review tasks';

    public function handle(GitHubApiService $github): int
    {
        $repos = Repository::where('is_active', true)->get();
        $created = 0;

        foreach ($repos as $repo) {
            try {
                $pullRequests = $github->listPullRequests($repo->owner, $repo->repo, 'open');

                foreach ($pullRequests as $pr) {
                    $prNumber = $pr['number'] ?? 0;
                    $prAuthor = $pr['user']['login'] ?? '';

                    // Skip PRs already reviewed
                    $existingTask = ReviewTask::where('repository_id', $repo->id)
                        ->where('pr_number', $prNumber)
                        ->whereIn('status', [
                            ReviewTask::STATUS_PENDING,
                            ReviewTask::STATUS_REVIEWING,
                            ReviewTask::STATUS_COMMENTED,
                            ReviewTask::STATUS_FIXING,
                            ReviewTask::STATUS_FIXED,
                        ])
                        ->first();

                    if ($existingTask) {
                        continue;
                    }

                    // Create new review task
                    $task = ReviewTask::create([
                        'repository_id' => $repo->id,
                        'pr_number' => $prNumber,
                        'pr_title' => $pr['title'] ?? '',
                        'pr_url' => $pr['html_url'] ?? '',
                        'pr_author' => $prAuthor,
                        'status' => ReviewTask::STATUS_PENDING,
                        'iteration' => 1,
                    ]);

                    ReviewPrJob::dispatch($task);
                    $created++;

                    $this->info("  Created review task #{$task->id} for {$repo->owner}/{$repo->repo}#{$prNumber}");

                    Log::info('Poll: Review task created', [
                        'task_id' => $task->id,
                        'pr' => "{$repo->owner}/{$repo->repo}#{$prNumber}",
                    ]);
                }
            } catch (\Throwable $e) {
                $this->error("  Error polling {$repo->owner}/{$repo->repo}: {$e->getMessage()}");

                Log::error('Poll: Failed to poll repository', [
                    'repo' => "{$repo->owner}/{$repo->repo}",
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Created {$created} new review tasks.");

        return self::SUCCESS;
    }
}
