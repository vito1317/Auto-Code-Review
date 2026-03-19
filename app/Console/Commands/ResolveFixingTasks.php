<?php

namespace App\Console\Commands;

use App\Models\ReviewTask;
use App\Services\GitHubApiService;
use App\Services\JulesApiService;
use Illuminate\Console\Command;

class ResolveFixingTasks extends Command
{
    protected $signature = 'jules:resolve';

    protected $description = 'Resolve stuck fixing tasks by checking Jules session states';

    public function handle(JulesApiService $jules, GitHubApiService $github): int
    {
        $tasks = ReviewTask::where('status', 'fixing')
            ->whereNotNull('jules_session_id')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No fixing tasks found.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            $this->line("Processing Task #{$task->id} (Session: {$task->jules_session_id})...");

            try {
                $session = $jules->getSession($task->jules_session_id);
                $state = $session['state'] ?? 'UNKNOWN';

                if ($state === 'COMPLETED') {
                    $prUrl = $jules->extractPrUrl($session);

                    $task->update([
                        'status' => ReviewTask::STATUS_FIXED,
                        'jules_fix_pr_url' => $prUrl,
                    ]);

                    $this->info('  ✅ Marked as fixed (PR: '.($prUrl ?: 'none').')');

                    if ($prUrl && $task->repository) {
                        $github->createIssueComment(
                            $task->repository->owner,
                            $task->repository->repo,
                            $task->pr_number,
                            "## 🔧 Auto-Fix PR Created\n\nJules has created a fix PR: {$prUrl}",
                        );
                        $this->info("  📝 Posted comment on PR #{$task->pr_number}");
                    }
                } elseif ($state === 'FAILED') {
                    $task->update([
                        'status' => ReviewTask::STATUS_FAILED,
                        'error_message' => 'Jules session failed.',
                    ]);
                    $this->warn('  ❌ Marked as failed');
                } else {
                    $this->comment("  ⏳ Still running (state: {$state})");
                }
            } catch (\Throwable $e) {
                $this->error("  Error: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
