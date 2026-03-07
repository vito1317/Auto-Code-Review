<?php

namespace App\Console\Commands;

use App\Jobs\PollJulesSessionJob;
use App\Models\ReviewTask;
use Illuminate\Console\Command;

class PollJulesSessionsCommand extends Command
{
    protected $signature = 'jules:poll-sessions';
    protected $description = 'Poll all active Jules sessions for completion';

    public function handle(): int
    {
        $tasks = ReviewTask::waitingOnJules()->get();

        if ($tasks->isEmpty()) {
            $this->info('No active Jules sessions to poll.');
            return self::SUCCESS;
        }

        $this->info("Polling {$tasks->count()} active Jules session(s)...");

        foreach ($tasks as $task) {
            $this->line("  → Task #{$task->id} (PR #{$task->pr_number}) — Session: {$task->jules_session_id}");
            PollJulesSessionJob::dispatch($task);
        }

        $this->info('Poll jobs dispatched.');
        return self::SUCCESS;
    }
}
