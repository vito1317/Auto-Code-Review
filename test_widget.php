<?php
use App\Models\Repository;
use App\Models\ReviewTask;

$userId = 1;
$userRepoQuery = Repository::select('id')->where('user_id', $userId);
$taskQuery = ReviewTask::whereIn('repository_id', $userRepoQuery);

$totalReviews = (clone $taskQuery)->count();
$approvedReviews = (clone $taskQuery)->where('status', 'approved')->count();
$fixedReviews = (clone $taskQuery)->where('status', 'fixed')->count();
$failedReviews = (clone $taskQuery)->where('status', 'failed')->count();
$activeRepos = Repository::where('user_id', $userId)->where('is_active', true)->count();
$pendingReviews = (clone $taskQuery)->whereIn('status', ['pending', 'reviewing', 'fixing'])->count();

$passRate = $totalReviews > 0 ? round(($approvedReviews / $totalReviews) * 100, 1) : 0;

$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $date = now()->subDays($i)->toDateString();
    $trend[] = ReviewTask::whereIn('repository_id', clone $userRepoQuery)
        ->whereDate('created_at', $date)->count();
}

echo "Success! Total: $totalReviews\n";
