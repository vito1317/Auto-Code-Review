<?php

namespace App\Filament\Resources\ReviewTaskResource\Pages;

use App\Filament\Resources\ReviewTaskResource;
use App\Jobs\ReviewPrJob;
use App\Models\ReviewTask;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListReviewTasks extends ListRecords
{
    protected static string $resource = ReviewTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('retry_all_failed')
                ->label('Retry All Failed')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Retry All Failed Tasks')
                ->modalDescription('This will reset all failed tasks to pending and re-dispatch them for review. Are you sure?')
                ->modalSubmitActionLabel('Retry All')
                ->action(function () {
                    $query = ReviewTask::where('status', ReviewTask::STATUS_FAILED);

                    if (! auth()->user()->isAdmin()) {
                        $query->whereHas('repository', fn ($q) => $q->where('user_id', auth()->id()));
                    }

                    $tasks = $query->get();

                    if ($tasks->isEmpty()) {
                        Notification::make()
                            ->title('No failed tasks found')
                            ->info()
                            ->send();

                        return;
                    }

                    $count = 0;
                    foreach ($tasks as $task) {
                        $task->update([
                            'status' => ReviewTask::STATUS_PENDING,
                            'error_message' => null,
                            'iteration' => $task->iteration + 1,
                        ]);
                        ReviewPrJob::dispatch($task);
                        $count++;
                    }

                    Notification::make()
                        ->title("Retrying {$count} failed tasks")
                        ->success()
                        ->send();
                }),
            Actions\Action::make('merge_all_approved')
                ->label('Merge All Approved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Merge All Approved PRs')
                ->modalDescription('This will attempt to merge all approved/fixed PRs. Are you sure?')
                ->modalSubmitActionLabel('Merge All')
                ->action(function () {
                    $query = ReviewTask::latestIteration()->whereIn('status', [
                        ReviewTask::STATUS_APPROVED,
                        ReviewTask::STATUS_FIXED,
                    ])->where('pr_status', ReviewTask::PR_STATUS_OPEN)
                        ->with('repository');

                    if (! auth()->user()->isAdmin()) {
                        $query->whereHas('repository', fn ($q) => $q->where('user_id', auth()->id()));
                    }

                    $tasks = $query->get();

                    if ($tasks->isEmpty()) {
                        Notification::make()
                            ->title('No approved tasks to merge')
                            ->info()
                            ->send();

                        return;
                    }

                    $userId = auth()->id();
                    $delay = 0;
                    foreach ($tasks as $task) {
                        $task->update([
                            'merge_status' => ReviewTask::MERGE_QUEUED,
                            'merge_message' => 'Queued for merge',
                        ]);
                        \App\Jobs\MergePrJob::dispatch($task, $userId)
                            ->delay(now()->addSeconds($delay));
                        $delay += 5; // 5-second gap between each merge
                    }

                    Notification::make()
                        ->title("Queued {$tasks->count()} PRs for merging")
                        ->body('Merges are running in the background. Refresh the page in a moment to see updated statuses.')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('sync_pr_status')
                ->label('Sync PR Status')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Sync PR Status from GitHub')
                ->modalDescription('This will fetch the current status (open/closed/merged) of all open PRs from GitHub in the background.')
                ->modalSubmitActionLabel('Sync')
                ->action(function () {
                    $userId = auth()->user()->isAdmin() ? null : auth()->id();
                    \App\Jobs\SyncPrStatusJob::dispatch($userId);

                    Notification::make()
                        ->title('PR status sync started')
                        ->body('The sync is running in the background. Refresh the page in a moment to see updated statuses.')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('ai_merge_all')
                ->label('AI Merge All')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('AI Merge All Conflicting PRs')
                ->modalDescription('This will use AI to resolve merge conflicts for all approved/fixed PRs that are still open. Each PR will be processed in the background.')
                ->modalSubmitActionLabel('Start AI Merge')
                ->action(function () {
                    $query = ReviewTask::latestIteration()
                        ->where('pr_status', ReviewTask::PR_STATUS_OPEN)
                        ->whereIn('status', [
                            ReviewTask::STATUS_APPROVED,
                            ReviewTask::STATUS_FIXED,
                        ])
                        ->with('repository');

                    if (! auth()->user()->isAdmin()) {
                        $query->whereHas('repository', fn ($q) => $q->where('user_id', auth()->id()));
                    }

                    $tasks = $query->get();

                    if ($tasks->isEmpty()) {
                        Notification::make()
                            ->title('No open approved PRs to merge')
                            ->info()
                            ->send();

                        return;
                    }

                    $userId = auth()->id();
                    foreach ($tasks as $task) {
                        $task->update(['ai_merge_status' => ReviewTask::AI_MERGE_PENDING, 'ai_merge_message' => 'Queued for AI merge']);
                        \App\Jobs\AiMergeJob::dispatch($task, $userId);
                    }

                    Notification::make()
                        ->title("AI Merge dispatched for {$tasks->count()} PRs")
                        ->body('Processing in background. Refresh to see results.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
