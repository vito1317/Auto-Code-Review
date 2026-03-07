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
        $totalReviews = ReviewTask::count();
        $approvedReviews = ReviewTask::where('status', 'approved')->count();
        $fixedReviews = ReviewTask::where('status', 'fixed')->count();
        $failedReviews = ReviewTask::where('status', 'failed')->count();
        $activeRepos = Repository::where('is_active', true)->count();
        $pendingReviews = ReviewTask::whereIn('status', ['pending', 'reviewing', 'fixing'])->count();

        $passRate = $totalReviews > 0
            ? round(($approvedReviews / $totalReviews) * 100, 1)
            : 0;

        return [
            Stat::make('Total Reviews', $totalReviews)
                ->description("{$pendingReviews} in progress")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary')
                ->chart($this->getReviewTrend()),

            Stat::make('Pass Rate', "{$passRate}%")
                ->description("{$approvedReviews} approved, {$fixedReviews} auto-fixed")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($passRate >= 70 ? 'success' : ($passRate >= 40 ? 'warning' : 'danger')),

            Stat::make('Active Repos', $activeRepos)
                ->description(Repository::count() . ' total repositories')
                ->descriptionIcon('heroicon-m-code-bracket-square')
                ->color('info'),

            Stat::make('Failed Reviews', $failedReviews)
                ->description('Require attention')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedReviews > 0 ? 'danger' : 'success'),
        ];
    }

    private function getReviewTrend(): array
    {
        // Get review counts for the last 7 days
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $trend[] = ReviewTask::whereDate('created_at', $date)->count();
        }

        return $trend;
    }
}
