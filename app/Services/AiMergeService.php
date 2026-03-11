<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiMergeService
{
    public function __construct(
        private GitHubApiService $github,
    ) {}

    /**
     * Set the user context for per-user API key/token resolution.
     */
    public function forUser(?int $userId): static
    {
        $this->github->forUser($userId);

        return $this;
    }

    /**
     * Resolve merge conflicts for a PR using AI and attempt to merge.
     *
     * @return array{success: bool, message: string}
     */
    public function resolveAndMerge(
        string $owner,
        string $repo,
        int $prNumber,
        ?int $userId = null,
        ?callable $onProgress = null,
    ): array {
        $progress = fn (string $msg) => $onProgress ? $onProgress($msg) : null;
        Log::info('AI Merge: Starting', compact('owner', 'repo', 'prNumber'));

        // 1. Get PR info to find head and base branches
        $progress('Fetching PR info...');
        $pr = $this->github->getPullRequest($owner, $repo, $prNumber);
        $headBranch = $pr['head']['ref'] ?? null;
        $baseBranch = $pr['base']['ref'] ?? null;

        if (! $headBranch || ! $baseBranch) {
            return ['success' => false, 'message' => 'Could not determine head/base branches'];
        }

        // 2. Check if PR is already merged
        if (($pr['merged'] ?? false) || ($pr['state'] ?? '') === 'closed') {
            return ['success' => true, 'message' => 'PR is already merged/closed'];
        }

        // 3. Try normal merge first
        $progress('Trying direct merge...');
        try {
            $this->github->mergePullRequest($owner, $repo, $prNumber, "Merge PR #{$prNumber}");

            return ['success' => true, 'message' => 'PR merged successfully (no conflicts)'];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'already in progress')) {
                // Another process is already merging, wait and check
                sleep(5);

                return ['success' => true, 'message' => 'Merge already in progress by another process'];
            }
            if (! str_contains($e->getMessage(), 'not mergeable')) {
                return ['success' => false, 'message' => 'Merge failed: '.$e->getMessage()];
            }
            Log::info('AI Merge: Conflicts detected, resolving with AI', compact('prNumber'));
            $progress('Merge conflicts detected, comparing branches...');
        }

        // 3. Compare branches to find changed files
        try {
            $comparison = $this->github->compareBranches($owner, $repo, $headBranch, $baseBranch);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to compare branches: '.$e->getMessage()];
        }

        $files = $comparison['files'] ?? [];
        if (empty($files)) {
            return ['success' => false, 'message' => 'No changed files found'];
        }

        // 4. For each changed file, get content from both branches and AI-merge
        $modifiedFiles = collect($files)->where('status', 'modified');
        $totalFiles = $modifiedFiles->count();
        $resolved = 0;
        $current = 0;
        foreach ($files as $file) {
            $path = $file['filename'];
            $status = $file['status']; // added, removed, modified, renamed

            // Only process modified files (conflicts happen in modified files)
            if ($status !== 'modified') {
                continue;
            }

            $current++;
            $progress("Resolving file {$current}/{$totalFiles}: {$path}");

            try {
                $baseContent = $this->github->getFileContentRaw($owner, $repo, $path, $baseBranch);
                $headContent = $this->github->getFileContentRaw($owner, $repo, $path, $headBranch);

                // If contents are the same, skip
                if ($baseContent === $headContent) {
                    continue;
                }

                // AI merge the two versions
                $mergedContent = $this->aiMergeFile($path, $baseContent, $headContent, $userId);

                if ($mergedContent === null) {
                    Log::warning('AI Merge: AI could not merge file', ['path' => $path]);

                    continue;
                }

                // Get the file SHA from the head branch (needed for update)
                $headFile = $this->github->getFileContent($owner, $repo, $path, $headBranch);
                $sha = $headFile['sha'] ?? '';

                // Push the merged content
                $this->github->updateFileContent(
                    $owner,
                    $repo,
                    $path,
                    $mergedContent,
                    "AI merge: resolve conflicts in {$path} for PR #{$prNumber}",
                    $headBranch,
                    $sha,
                );

                $resolved++;

                Log::info('AI Merge: File resolved', ['path' => $path, 'pr' => $prNumber]);
                $progress("Resolved {$resolved}/{$totalFiles}: {$path} ✅");

                // Small delay to avoid rate limits
                usleep(500000);

            } catch (\Throwable $e) {
                Log::warning('AI Merge: Failed to resolve file', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($resolved === 0) {
            return ['success' => false, 'message' => 'AI could not resolve any conflicting files'];
        }

        // 5. Merge base branch into head to create a proper merge commit
        //    This resolves Git's divergence detection and makes the PR mergeable
        $progress("Resolved {$resolved} files. Merging {$baseBranch} into {$headBranch}...");
        try {
            $this->github->mergeBranches(
                $owner,
                $repo,
                $headBranch,  // base: merge INTO head
                $baseBranch,  // head: merge FROM base
                "Merge {$baseBranch} into {$headBranch}: AI-resolved conflicts for PR #{$prNumber}",
            );
            Log::info('AI Merge: Base merged into head branch', compact('prNumber'));
            sleep(3);
        } catch (\Throwable $e) {
            Log::warning('AI Merge: Branch merge failed, trying updatePullRequestBranch', [
                'error' => $e->getMessage(),
            ]);

            // Fallback: try updatePullRequestBranch
            try {
                $this->github->updatePullRequestBranch($owner, $repo, $prNumber);
                Log::info('AI Merge: Branch updated via updatePullRequestBranch', compact('prNumber'));
                sleep(3);
            } catch (\Throwable $e2) {
                Log::warning('AI Merge: Both branch update methods failed', [
                    'error' => $e2->getMessage(),
                ]);
            }
        }

        // 7. Retry merge (with retry for 'already in progress')
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $progress("Final merge: attempt {$attempt}/{$maxRetries}...");
            try {
                $this->github->mergePullRequest(
                    $owner,
                    $repo,
                    $prNumber,
                    "AI-assisted merge PR #{$prNumber}: conflicts resolved by AI",
                );

                Log::info('AI Merge: Successfully merged after conflict resolution', compact('prNumber'));

                return ['success' => true, 'message' => "Resolved {$resolved} files and merged successfully"];
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'already in progress') && $attempt < $maxRetries) {
                    sleep(5);

                    continue;
                }

                return [
                    'success' => false,
                    'message' => "Resolved {$resolved} files but merge still failed: ".$e->getMessage(),
                ];
            }
        }

        return ['success' => false, 'message' => "Resolved {$resolved} files but merge failed after retries"];
    }

    /**
     * Use AI to merge two versions of a file.
     */
    private function aiMergeFile(
        string $path,
        string $baseContent,
        string $headContent,
        ?int $userId = null,
    ): ?string {
        $provider = Setting::getValue('ai_provider', 'gemini', $userId);

        $prompt = $this->buildMergePrompt($path, $baseContent, $headContent);

        try {
            return match ($provider) {
                'lmstudio' => $this->mergeViaLmStudio($prompt, $userId),
                default => $this->mergeViaGemini($prompt, $userId),
            };
        } catch (\Throwable $e) {
            Log::error('AI Merge: AI call failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function buildMergePrompt(string $path, string $baseContent, string $headContent): string
    {
        return <<<PROMPT
You are a code merge assistant. Two branches have modified the same file and there are conflicts.
Your job is to produce the final merged version that incorporates changes from BOTH versions.

File: {$path}

=== BASE BRANCH VERSION (target branch) ===
{$baseContent}
=== END BASE VERSION ===

=== HEAD BRANCH VERSION (PR branch with new changes) ===
{$headContent}
=== END HEAD VERSION ===

Instructions:
1. Merge both versions, keeping ALL meaningful changes from both sides
2. If both sides modified the same section differently, prefer the HEAD (PR) version but incorporate any BASE-only changes
3. Do NOT include conflict markers (<<<, ===, >>>)
4. Return ONLY the final merged file content, nothing else — no explanations, no markdown fences, no comments about the merge
5. Preserve the original file encoding and line endings

Output the merged file content:
PROMPT;
    }

    private function mergeViaGemini(string $prompt, ?int $userId = null): ?string
    {
        $apiKey = Setting::getValue('gemini_api_key', config('services.gemini.api_key', ''), $userId);
        $model = Setting::getValue('gemini_model', 'gemini-2.0-flash', $userId);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout(120)->post($url, [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("Gemini API error ({$response->status()})");
        }

        $text = $response->json('candidates.0.content.parts.0.text', '');

        // Strip any thinking tags
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);

        return trim($text) ?: null;
    }

    private function mergeViaLmStudio(string $prompt, ?int $userId = null): ?string
    {
        $baseUrl = Setting::getValue('lmstudio_base_url', 'http://localhost:1234', $userId);
        $model = Setting::getValue('lmstudio_model', 'default', $userId);
        $url = rtrim($baseUrl, '/').'/v1/chat/completions';

        $response = Http::timeout(180)->post($url, [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a code merge assistant. Output ONLY the merged file content, nothing else.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 8192,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("LM Studio API error ({$response->status()})");
        }

        $text = $response->json('choices.0.message.content', '');

        // Strip thinking tags
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);

        return trim($text) ?: null;
    }
}
