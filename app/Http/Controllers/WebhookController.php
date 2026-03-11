<?php

namespace App\Http\Controllers;

use App\Jobs\ReviewPrJob;
use App\Models\Repository;
use App\Models\ReviewTask;
use App\Services\GitHubApiService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, GitHubApiService $github): Response
    {
        $event = $request->header('X-GitHub-Event');
        $payload = $request->getContent();

        Log::info('GitHub webhook received', ['event' => $event]);

        // Handle ping event (sent when webhook is first configured)
        if ($event === 'ping') {
            return response('pong', 200);
        }

        // Only process pull_request events
        if ($event !== 'pull_request') {
            return response('Event ignored', 200);
        }

        $data = $request->json()->all();
        $action = $data['action'] ?? '';

        // Only process opened and synchronize (new commits pushed) actions
        if (! in_array($action, ['opened', 'synchronize'])) {
            Log::info('PR action ignored', ['action' => $action]);

            return response('Action ignored', 200);
        }

        $prData = $data['pull_request'] ?? [];
        $repoData = $data['repository'] ?? [];

        $owner = $repoData['owner']['login'] ?? '';
        $repo = $repoData['name'] ?? '';

        // Find the repository in our database
        $repository = Repository::where('owner', $owner)
            ->where('repo', $repo)
            ->where('is_active', true)
            ->first();

        if (! $repository) {
            Log::info('Repository not configured or inactive', ['owner' => $owner, 'repo' => $repo]);

            return response('Repository not configured', 200);
        }

        // Verify webhook signature using the repo owner's webhook secret
        $webhookSecret = \App\Models\Setting::getValue('github_webhook_secret', '', $repository->user_id);
        if ($webhookSecret) {
            $signature = $request->header('X-Hub-Signature-256', '');
            if (! $github->verifyWebhookSignature($payload, $signature, $webhookSecret)) {
                Log::warning('Invalid webhook signature', ['owner' => $owner, 'repo' => $repo]);

                return response('Invalid signature', 403);
            }
        }

        $prNumber = $prData['number'] ?? 0;
        $prTitle = $prData['title'] ?? '';
        $prUrl = $prData['html_url'] ?? '';
        $prAuthor = $prData['user']['login'] ?? '';

        // Check if we already have a task for this PR
        $existingTask = ReviewTask::where('repository_id', $repository->id)
            ->where('pr_number', $prNumber)
            ->latest()
            ->first();

        // Skip synchronize events caused by AI merge commits or when ANY task for this PR is actively processing
        if ($action === 'synchronize') {
            // Skip if PR is already merged or closed
            $prState = $prData['state'] ?? 'open';
            $prMerged = $prData['merged'] ?? false;
            if ($prMerged || $prState === 'closed') {
                Log::info('Skipping synchronize: PR already merged/closed', ['pr' => $prNumber]);

                return response('PR merged/closed, skipping', 200);
            }

            // Skip if existing task already marked as merged
            if ($existingTask && $existingTask->pr_status === ReviewTask::PR_STATUS_MERGED) {
                Log::info('Skipping synchronize: task already merged', ['pr' => $prNumber]);

                return response('Already merged, skipping', 200);
            }

            // Skip if ANY task for this PR has AI merge in progress
            $hasActiveAiMerge = ReviewTask::where('repository_id', $repository->id)
                ->where('pr_number', $prNumber)
                ->whereIn('ai_merge_status', ['pending', 'processing'])
                ->exists();

            if ($hasActiveAiMerge) {
                Log::info('Skipping synchronize: AI merge in progress for PR', ['pr' => $prNumber]);

                return response('AI merge in progress, skipping', 200);
            }

            // Skip if ANY task for this PR is currently being reviewed or fixed
            $hasActiveProcessing = ReviewTask::where('repository_id', $repository->id)
                ->where('pr_number', $prNumber)
                ->whereIn('status', [ReviewTask::STATUS_REVIEWING, ReviewTask::STATUS_FIXING])
                ->exists();

            if ($hasActiveProcessing) {
                Log::info('Skipping synchronize: task actively processing', ['pr' => $prNumber]);

                return response('Task processing, skipping', 200);
            }

            // Skip commits made by the bot (AI merge commits)
            $lastCommitMsg = $data['head_commit']['message'] ?? ($data['after'] ?? '');
            if (str_contains($lastCommitMsg, 'AI merge:') || str_contains($lastCommitMsg, 'AI-assisted merge')) {
                Log::info('Skipping synchronize: AI merge commit detected', ['pr' => $prNumber]);

                return response('AI commit, skipping', 200);
            }
        }

        // For synchronize events, update the existing task instead of creating a new one
        if ($action === 'synchronize' && $existingTask) {
            $existingTask->update([
                'iteration' => $existingTask->iteration + 1,
                'status' => ReviewTask::STATUS_PENDING,
                'pr_title' => $prTitle,
                'error_message' => null,
                'ai_merge_status' => null,
                'ai_merge_message' => null,
            ]);

            Log::info('Review task updated for new push', [
                'task_id' => $existingTask->id,
                'pr' => "{$owner}/{$repo}#{$prNumber}",
                'iteration' => $existingTask->iteration,
            ]);

            ReviewPrJob::dispatch($existingTask);

            return response()->json(['status' => 'updated', 'task_id' => $existingTask->id]);
        }

        // Create review task (for 'opened' or new PRs)
        $task = ReviewTask::create([
            'repository_id' => $repository->id,
            'pr_number' => $prNumber,
            'pr_title' => $prTitle,
            'pr_url' => $prUrl,
            'pr_author' => $prAuthor,
            'status' => ReviewTask::STATUS_PENDING,
            'iteration' => 1,
        ]);

        Log::info('Review task created', [
            'task_id' => $task->id,
            'pr' => "{$owner}/{$repo}#{$prNumber}",
            'iteration' => $iteration,
        ]);

        // Dispatch the review job
        ReviewPrJob::dispatch($task);

        return response('Review queued', 200);
    }
}
