<?php

namespace App\Filament\Widgets;

use App\Models\Repository;
use App\Models\ReviewTask;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReviewStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $userId = auth()->id();

        // Scope all queries to current user's repositories
        $userRepoQuery = Repository::select('id')->where('user_id', $userId);
        $taskQuery = ReviewTask::whereIn('repository_id', $userRepoQuery);

        $totalReviews = (clone $taskQuery)->count();
        $approvedReviews = (clone $taskQuery)->where('status', 'approved')->count();
        $fixedReviews = (clone $taskQuery)->where('status', 'fixed')->count();
        $failedReviews = (clone $taskQuery)->where('status', 'failed')->count();
        $activeRepos = Repository::where('user_id', $userId)->where('is_active', true)->count();
        $pendingReviews = (clone $taskQuery)->whereIn('status', ['pending', 'reviewing', 'fixing'])->count();

        $passRate = $totalReviews > 0
            ? round(($approvedReviews / $totalReviews) * 100, 1)
            : 0;

        return [
            Stat::make('Total Reviews', $totalReviews)
                ->description("{$pendingReviews} in progress")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary')
                ->chart($this->getReviewTrend($userRepoQuery)),

            Stat::make('Pass Rate', "{$passRate}%")
                ->description("{$approvedReviews} approved, {$fixedReviews} auto-fixed")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($passRate >= 70 ? 'success' : ($passRate >= 40 ? 'warning' : 'danger')),

            Stat::make('Active Repos', $activeRepos)
                ->description(Repository::where('user_id', $userId)->count().' total repositories')
                ->descriptionIcon('heroicon-m-code-bracket-square')
                ->color('info'),

            Stat::make('Failed Reviews', $failedReviews)
                ->description('Require attention')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedReviews > 0 ? 'danger' : 'success'),
        ];
    }

    private function getReviewTrend($userRepoQuery): array
    {
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $trend[] = ReviewTask::whereIn('repository_id', clone $userRepoQuery)
                ->whereDate('created_at', $date)->count();
        }

        return $trend;
    }
}
