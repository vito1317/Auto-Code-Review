<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubApiService
{
    private const BASE_URL = 'https://api.github.com';

    private ?string $token = null;

    /**
     * Get the GitHub token from settings.
     */
    private function getToken(): string
    {
        if ($this->token === null) {
            $this->token = Setting::getValue('github_token', config('services.github.token', ''));
        }

        return $this->token;
    }

    /**
     * Make an authenticated request to GitHub API.
     */
    private function request(string $method, string $endpoint, array $data = [], array $headers = []): mixed
    {
        $url = self::BASE_URL . $endpoint;

        $defaultHeaders = [
            'Authorization' => "Bearer {$this->getToken()}",
            'Accept' => 'application/vnd.github.v3+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $response = Http::withHeaders(array_merge($defaultHeaders, $headers))
            ->{$method}($url, $data);

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
     * @param string $event  COMMENT, APPROVE, or REQUEST_CHANGES
     * @param array  $comments  Array of line-specific comments [{path, position, body}]
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

        if (!empty($comments)) {
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
     * Verify a GitHub webhook signature.
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        if (empty($signature)) {
            return false;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

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
}
