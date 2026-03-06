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
        if (!in_array($action, ['opened', 'synchronize'])) {
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

        if (!$repository) {
            Log::info('Repository not configured or inactive', ['owner' => $owner, 'repo' => $repo]);
            return response('Repository not configured', 200);
        }

        // Verify webhook signature if secret is configured
        if ($repository->webhook_secret) {
            $signature = $request->header('X-Hub-Signature-256', '');
            if (!$github->verifyWebhookSignature($payload, $signature, $repository->webhook_secret)) {
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

        // For synchronize events, increment iteration
        $iteration = 1;
        if ($action === 'synchronize' && $existingTask) {
            $iteration = $existingTask->iteration + 1;
        }

        // Create or update review task
        $task = ReviewTask::create([
            'repository_id' => $repository->id,
            'pr_number' => $prNumber,
            'pr_title' => $prTitle,
            'pr_url' => $prUrl,
            'pr_author' => $prAuthor,
            'status' => ReviewTask::STATUS_PENDING,
            'iteration' => $iteration,
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
