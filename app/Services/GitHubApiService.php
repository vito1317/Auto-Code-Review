<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubApiService
{
    private const BASE_URL = 'https://api.github.com';

    private ?int $userId = null;

    private array $tokenCache = [];

    /**
     * Set the user context for per-user token resolution.
     */
    public function forUser(?int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Get the GitHub token from settings (per-user with global fallback).
     */
    private function getToken(): string
    {
        $key = $this->userId ?? 0;

        if (! isset($this->tokenCache[$key])) {
            $this->tokenCache[$key] = Setting::getValue('github_token', config('services.github.token', ''), $this->userId);
        }

        return $this->tokenCache[$key];
    }

    /**
     * Make an authenticated request to GitHub API.
     */
    private function request(string $method, string $endpoint, array $data = [], array $headers = []): mixed
    {
        $url = self::BASE_URL.$endpoint;

        $defaultHeaders = [
            'Authorization' => "Bearer {$this->getToken()}",
            'Accept' => 'application/vnd.github.v3+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $client = Http::withHeaders(array_merge($defaultHeaders, $headers));

        // Non-GET requests must send JSON body for GitHub API
        if ($method !== 'get') {
            $client = $client->asJson();
        }

        $response = $client->{$method}($url, $data);

        if ($response->failed()) {
            Log::error('GitHub API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException(
                "GitHub API error ({$response->status()}): {$response->body()}"
            );
        }

        // For diff requests, return raw body
        if (isset($headers['Accept']) && $headers['Accept'] === 'application/vnd.github.v3.diff') {
            return $response->body();
        }

        return $response->json() ?? [];
    }

    /**
     * Get a pull request.
     */
    public function getPullRequest(string $owner, string $repo, int $prNumber): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/pulls/{$prNumber}");
    }

    /**
     * Get the diff of a pull request.
     */
    public function getPullRequestDiff(string $owner, string $repo, int $prNumber): string
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/pulls/{$prNumber}", [], [
            'Accept' => 'application/vnd.github.v3.diff',
        ]);
    }

    /**
     * Get files changed in a pull request.
     */
    public function getPullRequestFiles(string $owner, string $repo, int $prNumber): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/pulls/{$prNumber}/files");
    }

    /**
     * Create a review on a pull request.
     *
     * @param  string  $event  COMMENT, APPROVE, or REQUEST_CHANGES
     * @param  array  $comments  Array of line-specific comments [{path, position, body}]
     */
    public function createReview(
        string $owner,
        string $repo,
        int $prNumber,
        string $event,
        string $body,
        array $comments = [],
    ): array {
        $payload = [
            'event' => $event,
            'body' => $body,
        ];

        if (! empty($comments)) {
            $payload['comments'] = $comments;
        }

        return $this->request('post', "/repos/{$owner}/{$repo}/pulls/{$prNumber}/reviews", $payload);
    }

    /**
     * Create a general comment on a pull request (as an issue comment).
     */
    public function createIssueComment(string $owner, string $repo, int $prNumber, string $body): array
    {
        return $this->request('post', "/repos/{$owner}/{$repo}/issues/{$prNumber}/comments", [
            'body' => $body,
        ]);
    }

    /**
     * Create a review comment on a specific line.
     */
    public function createPullRequestComment(
        string $owner,
        string $repo,
        int $prNumber,
        string $body,
        string $commitId,
        string $path,
        int $line,
        string $side = 'RIGHT',
    ): array {
        return $this->request('post', "/repos/{$owner}/{$repo}/pulls/{$prNumber}/comments", [
            'body' => $body,
            'commit_id' => $commitId,
            'path' => $path,
            'line' => $line,
            'side' => $side,
        ]);
    }

    /**
     * Create a pull request.
     */
    public function createPullRequest(
        string $owner,
        string $repo,
        string $title,
        string $head,
        string $base,
        string $body = '',
    ): array {
        return $this->request('post', "/repos/{$owner}/{$repo}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ]);
    }

    /**
     * List branches for a repository.
     */
    public function listBranches(string $owner, string $repo, int $perPage = 100): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/branches", [
            'per_page' => $perPage,
        ]);
    }

    /**
     * Verify a GitHub webhook signature.
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        if (empty($signature)) {
            return false;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get the latest commit SHA of a pull request.
     */
    public function getPullRequestHeadSha(string $owner, string $repo, int $prNumber): string
    {
        $pr = $this->getPullRequest($owner, $repo, $prNumber);

        return $pr['head']['sha'] ?? '';
    }

    /**
     * List pull requests for a repository.
     */
    public function listPullRequests(string $owner, string $repo, string $state = 'open'): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/pulls", [
            'state' => $state,
        ]);
    }

    /**
     * Merge a pull request.
     *
     * @param  string  $mergeMethod  merge, squash, or rebase
     */
    public function mergePullRequest(
        string $owner,
        string $repo,
        int $prNumber,
        string $commitMessage = '',
        string $mergeMethod = 'squash',
    ): array {
        $payload = [
            'merge_method' => $mergeMethod,
        ];

        if ($commitMessage) {
            $payload['commit_message'] = $commitMessage;
        }

        return $this->request('put', "/repos/{$owner}/{$repo}/pulls/{$prNumber}/merge", $payload);
    }

    /**
     * Get the content of a file from a specific branch/ref.
     */
    public function getFileContent(string $owner, string $repo, string $path, string $ref): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/contents/{$path}", [
            'ref' => $ref,
        ]);
    }

    /**
     * Get raw file content decoded from base64.
     */
    public function getFileContentRaw(string $owner, string $repo, string $path, string $ref): string
    {
        $file = $this->getFileContent($owner, $repo, $path, $ref);

        return base64_decode($file['content'] ?? '');
    }

    /**
     * Update (or create) a file in the repository.
     */
    public function updateFileContent(
        string $owner,
        string $repo,
        string $path,
        string $content,
        string $message,
        string $branch,
        string $sha,
    ): array {
        return $this->request('put', "/repos/{$owner}/{$repo}/contents/{$path}", [
            'message' => $message,
            'content' => base64_encode($content),
            'sha' => $sha,
            'branch' => $branch,
        ]);
    }

    /**
     * Compare two branches/refs.
     */
    public function compareBranches(string $owner, string $repo, string $base, string $head): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/compare/{$base}...{$head}");
    }

    /**
     * Update a PR branch with the latest from the base branch.
     */
    public function updatePullRequestBranch(string $owner, string $repo, int $prNumber): array
    {
        return $this->request('put', "/repos/{$owner}/{$repo}/pulls/{$prNumber}/update-branch");
    }

    /**
     * Close a pull request.
     */
    public function closePullRequest(
        string $owner,
        string $repo,
        int $prNumber,
    ): array {
        return $this->request('patch', "/repos/{$owner}/{$repo}/pulls/{$prNumber}", [
            'state' => 'closed',
        ]);
    }

    /**
     * Merge one branch into another using GitHub's merge API.
     * This creates a proper merge commit, resolving branch divergence.
     */
    public function mergeBranches(
        string $owner,
        string $repo,
        string $base,
        string $head,
        string $commitMessage = 'Merge branch',
    ): array {
        return $this->request('post', "/repos/{$owner}/{$repo}/merges", [
            'base' => $base,
            'head' => $head,
            'commit_message' => $commitMessage,
        ]);
    }
}
