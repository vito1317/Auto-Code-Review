<?php

namespace App\Console\Commands;

use App\Models\ReviewTask;
use App\Services\JulesApiService;
use Illuminate\Console\Command;

class DebugJulesSessions extends Command
{
    protected $signature = 'jules:debug';

    protected $description = 'Check the status of all fixing Jules sessions';

    public function handle(JulesApiService $jules): int
    {
        $tasks = ReviewTask::where('status', 'fixing')
            ->whereNotNull('jules_session_id')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No fixing tasks found.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            $this->line("--- Task #{$task->id} (Session: {$task->jules_session_id}) ---");

            try {
                $session = $jules->getSession($task->jules_session_id);
                $this->info('  State: '.($session['state'] ?? 'N/A'));
                $this->info('  Status: '.json_encode($session['status'] ?? 'N/A'));

                // Check outputs
                $outputs = $session['outputs'] ?? [];
                $this->info('  Outputs: '.json_encode($outputs));

                // Check activities
                $activities = $jules->listActivities($task->jules_session_id, 5);
                foreach ($activities['activities'] ?? [] as $act) {
                    $type = array_keys(array_diff_key($act, array_flip(['name', 'createTime', 'updateTime'])))[0] ?? 'unknown';
                    $this->info("  Activity: {$type}");
                }
            } catch (\Throwable $e) {
                $this->error("  Error: {$e->getMessage()}");
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
