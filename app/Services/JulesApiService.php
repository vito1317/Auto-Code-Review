<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JulesApiService
{
    private const BASE_URL = 'https://jules.googleapis.com/v1alpha';

    private ?int $userId = null;

    private array $apiKeyCache = [];

    /**
     * Set the user context for per-user API key resolution.
     */
    public function forUser(?int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Get the API key from settings (per-user with global fallback).
     */
    private function getApiKey(): string
    {
        $key = $this->userId ?? 0;

        if (! isset($this->apiKeyCache[$key])) {
            $this->apiKeyCache[$key] = trim((string) Setting::getValue('jules_api_key', config('services.jules.api_key', ''), $this->userId));
        }

        return $this->apiKeyCache[$key];
    }

    /**
     * Make an authenticated request to the Jules API.
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = self::BASE_URL.$endpoint;
        $apiKey = $this->getApiKey();

        Log::debug('Jules API request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'api_key_length' => strlen($apiKey),
        ]);

        if ($method === 'get') {
            // GET: send data as query params, no Content-Type needed
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $apiKey,
            ])->timeout(120)->get($url, $data);
        } else {
            // POST/PUT: send data as JSON body
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $apiKey,
            ])->timeout(120)->asJson()->post($url, $data);
        }

        if ($response->failed()) {
            Log::error('Jules API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException(
                "Jules API error ({$response->status()}): {$response->body()}"
            );
        }

        return $response->json() ?? [];
    }

    /**
     * List all available sources (GitHub repositories).
     * GET /v1alpha/sources
     */
    public function listSources(?string $pageToken = null): array
    {
        $params = [];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        return $this->request('get', '/sources', $params);
    }

    /**
     * Get a specific source.
     * GET /v1alpha/{name}
     */
    public function getSource(string $name): array
    {
        return $this->request('get', "/{$name}");
    }

    /**
     * Create a new session (triggers Jules to work on a task).
     * POST /v1alpha/sessions
     *
     * @param  string  $source  Source name (e.g., "sources/github/owner/repo")
     * @param  string  $prompt  The task prompt for Jules
     * @param  string  $branch  Starting branch (default: "main")
     * @param  string  $automationMode  AUTO_CREATE_PR or empty
     * @param  bool  $requirePlanApproval  Whether to require plan approval
     * @param  string|null  $title  Optional session title
     */
    public function createSession(
        string $source,
        string $prompt,
        string $branch = 'main',
        string $automationMode = 'AUTO_CREATE_PR',
        bool $requirePlanApproval = false,
        ?string $title = null,
    ): array {
        $payload = [
            'prompt' => $prompt,
            'sourceContext' => [
                'source' => $source,
                'githubRepoContext' => [
                    'startingBranch' => $branch,
                ],
            ],
        ];

        if ($automationMode) {
            $payload['automationMode'] = $automationMode;
        }

        if ($requirePlanApproval) {
            $payload['requirePlanApproval'] = true;
        }

        if ($title) {
            $payload['title'] = $title;
        }

        return $this->request('post', '/sessions', $payload);
    }

    /**
     * Get a specific session by ID.
     * GET /v1alpha/sessions/{id}
     */
    public function getSession(string $sessionId): array
    {
        return $this->request('get', "/sessions/{$sessionId}");
    }

    /**
     * List sessions.
     * GET /v1alpha/sessions
     */
    public function listSessions(int $pageSize = 10, ?string $pageToken = null): array
    {
        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        return $this->request('get', '/sessions', $params);
    }

    /**
     * Approve a session's plan.
     * POST /v1alpha/sessions/{id}:approvePlan
     */
    public function approvePlan(string $sessionId): array
    {
        return $this->request('post', "/sessions/{$sessionId}:approvePlan");
    }

    /**
     * Submit (publish) the pull request for a completed session.
     * POST /v1alpha/sessions/{id}:submitPullRequest
     */
    public function submitPullRequest(string $sessionId): array
    {
        return $this->request('post', "/sessions/{$sessionId}:submitPullRequest");
    }

    /**
     * Send a message to a session (interact with Jules agent).
     * POST /v1alpha/sessions/{id}:sendMessage
     */
    public function sendMessage(string $sessionId, string $prompt): array
    {
        return $this->request('post', "/sessions/{$sessionId}:sendMessage", [
            'prompt' => $prompt,
        ]);
    }

    /**
     * List activities for a session.
     * GET /v1alpha/sessions/{id}/activities
     */
    public function listActivities(string $sessionId, int $pageSize = 30): array
    {
        return $this->request('get', "/sessions/{$sessionId}/activities", [
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * Check if a session has completed and extract outputs.
     */
    public function getSessionOutputs(string $sessionId): ?array
    {
        $session = $this->getSession($sessionId);
        $state = $session['state'] ?? '';

        // Check session state directly (most reliable)
        if (in_array($state, ['COMPLETED', 'FAILED', 'AWAITING_USER_FEEDBACK'])) {
            return [
                'completed' => true,
                'session' => $session,
                'state' => $state,
                'artifacts' => [],
            ];
        }

        // Fallback: check activities for sessionCompleted
        $activities = $this->listActivities($sessionId);

        foreach ($activities['activities'] ?? [] as $activity) {
            if (isset($activity['sessionCompleted'])) {
                return [
                    'completed' => true,
                    'session' => $session,
                    'state' => $state,
                    'artifacts' => $activity['artifacts'] ?? [],
                ];
            }
        }

        return [
            'completed' => false,
            'session' => $session,
            'state' => $state,
            'artifacts' => [],
        ];
    }

    /**
     * Extract PR URL from session outputs.
     */
    public function extractPrUrl(array $session): ?string
    {
        foreach ($session['outputs'] ?? [] as $output) {
            if (isset($output['pullRequest']['url'])) {
                return $output['pullRequest']['url'];
            }
        }

        return null;
    }

    /**
     * Extract the branch name Jules created from session outputs or activities.
     */
    public function extractBranchName(array $session): ?string
    {
        // Check outputs for branch info
        foreach ($session['outputs'] ?? [] as $output) {
            if (isset($output['pullRequest']['headBranch'])) {
                return $output['pullRequest']['headBranch'];
            }
            if (isset($output['branch'])) {
                return $output['branch'];
            }
        }

        // Check sourceContext for branch info
        if (isset($session['sourceContext']['githubRepoContext']['workingBranch'])) {
            return $session['sourceContext']['githubRepoContext']['workingBranch'];
        }

        // Fallback: try to find branch from session title/name
        $sessionId = $session['name'] ?? $session['id'] ?? '';
        if ($sessionId) {
            // Jules typically creates branches like jules/session-id
            return "jules/{$sessionId}";
        }

        return null;
    }
}
