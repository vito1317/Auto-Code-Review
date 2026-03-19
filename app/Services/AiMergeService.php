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
        $pr = $this->github->withRateLimitRetry(fn () => $this->github->getPullRequest($owner, $repo, $prNumber));
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

        // 3. Get only the files this PR actually changed (not all branch differences)
        $progress('Getting PR changed files...');
        try {
            $prFiles = $this->github->getPullRequestFiles($owner, $repo, $prNumber);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to get PR files: '.$e->getMessage()];
        }

        if (empty($prFiles)) {
            return ['success' => false, 'message' => 'No changed files found in PR'];
        }

        // 4. Find the merge-base to detect REAL conflicts
        //    A file only conflicts when BOTH branches modified it since the merge-base
        $progress('Finding merge-base and detecting real conflicts...');
        $mergeBase = null;
        try {
            $comparison = $this->github->compareBranches($owner, $repo, $baseBranch, $headBranch);
            $mergeBase = $comparison['merge_base_commit']['sha'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('AI Merge: Could not get merge-base', ['error' => $e->getMessage()]);
        }

        if (! $mergeBase) {
            return ['success' => false, 'message' => 'Could not determine merge-base commit'];
        }

        Log::info('AI Merge: merge-base found', ['sha' => substr($mergeBase, 0, 8), 'pr' => $prNumber]);

        $conflictingFiles = [];
        foreach ($prFiles as $file) {
            if (($file['status'] ?? '') !== 'modified') {
                continue;
            }
            $path = $file['filename'];
            try {
                // Get file at merge-base (common ancestor)
                $mergeBaseContent = $this->github->getFileContentRaw($owner, $repo, $path, $mergeBase);
                // Get file at base branch tip (e.g., current main)
                $baseContent = $this->github->getFileContentRaw($owner, $repo, $path, $baseBranch);

                // If base branch hasn't changed this file since merge-base, no conflict
                if ($mergeBaseContent === $baseContent) {
                    continue;
                }

                // Both branches modified this file — real conflict!
                $headContent = $this->github->getFileContentRaw($owner, $repo, $path, $headBranch);
                $conflictingFiles[] = [
                    'path' => $path,
                    'mergeBase' => $mergeBaseContent,
                    'base' => $baseContent,
                    'head' => $headContent,
                ];
                Log::info('AI Merge: Real conflict detected', ['path' => $path, 'pr' => $prNumber]);
            } catch (\Throwable $e) {
                continue;
            }
        }

        $totalFiles = count($conflictingFiles);
        if ($totalFiles === 0) {
            return ['success' => false, 'message' => 'No conflicting files detected'];
        }

        $progress("Found {$totalFiles} conflicting file(s), resolving...");
        $resolved = 0;
        $current = 0;
        foreach ($conflictingFiles as $cf) {
            $path = $cf['path'];

            $current++;
            $progress("Resolving file {$current}/{$totalFiles}: {$path}");

            try {
                // Use git merge-file for proper 3-way merge
                $mergeResult = $this->gitMergeFile($cf['mergeBase'], $cf['base'], $cf['head']);

                if ($mergeResult['clean']) {
                    // Clean merge — no actual conflicts, git resolved it automatically!
                    $mergedContent = $mergeResult['content'];
                    $progress("Auto-merged {$current}/{$totalFiles}: {$path} (no conflicts) ✅");
                } else {
                    // Has conflicts — extract and send only conflict sections to AI
                    $conflicts = $this->extractConflictSections($mergeResult['content']);
                    $conflictCount = count($conflicts);
                    $progress("AI resolving {$conflictCount} conflict(s) in {$path}...");

                    Log::info('AI Merge: Resolving conflicts via AI', [
                        'path' => $path,
                        'conflictCount' => $conflictCount,
                    ]);

                    $mergedContent = $this->resolveConflictsWithAi(
                        $path,
                        $mergeResult['content'],
                        $conflicts,
                        $userId,
                    );

                    if ($mergedContent === null) {
                        Log::warning('AI Merge: AI could not resolve conflicts', ['path' => $path]);

                        continue;
                    }
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
            sleep(10); // GitHub needs time to update mergeable state
        } catch (\Throwable $e) {
            Log::warning('AI Merge: Branch merge failed, trying updatePullRequestBranch', [
                'error' => $e->getMessage(),
            ]);

            // Fallback: try updatePullRequestBranch
            try {
                $this->github->updatePullRequestBranch($owner, $repo, $prNumber);
                Log::info('AI Merge: Branch updated via updatePullRequestBranch', compact('prNumber'));
                sleep(10);
            } catch (\Throwable $e2) {
                Log::warning('AI Merge: Both branch update methods failed', [
                    'error' => $e2->getMessage(),
                ]);
            }
        }

        // 7. Retry merge (with retry for 'already in progress')
        $maxRetries = 5;
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
                if ($attempt < $maxRetries) {
                    Log::info('AI Merge: Merge attempt failed, waiting before retry', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    sleep(5 * $attempt); // Progressive backoff: 5s, 10s, 15s, 20s

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
     * Perform a 3-way merge using git merge-file.
     * Returns ['clean' => bool, 'content' => string]
     */
    private function gitMergeFile(string $mergeBaseContent, string $baseContent, string $headContent): array
    {
        $tmpDir = sys_get_temp_dir();
        $ancestorFile = tempnam($tmpDir, 'merge_ancestor_');
        $oursFile = tempnam($tmpDir, 'merge_ours_');
        $theirsFile = tempnam($tmpDir, 'merge_theirs_');

        try {
            file_put_contents($ancestorFile, $mergeBaseContent);
            file_put_contents($oursFile, $baseContent);     // base branch (main)
            file_put_contents($theirsFile, $headContent);    // PR branch

            // git merge-file -p: outputs merged content to stdout
            // Exit code: 0 = clean merge, 1 = conflicts, <0 = error
            $output = [];
            $exitCode = 0;
            exec('git merge-file -p '.escapeshellarg($oursFile).' '.escapeshellarg($ancestorFile).' '.escapeshellarg($theirsFile).' 2>/dev/null', $output, $exitCode);

            $content = implode("\n", $output);

            return [
                'clean' => $exitCode === 0,
                'content' => $content,
            ];
        } finally {
            @unlink($ancestorFile);
            @unlink($oursFile);
            @unlink($theirsFile);
        }
    }

    /**
     * Extract conflict sections from git merge-file output.
     */
    private function extractConflictSections(string $content): array
    {
        $lines = explode("\n", $content);
        $conflicts = [];
        $inConflict = false;
        $currentConflict = null;
        $startLine = 0;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '<<<<<<<')) {
                $inConflict = true;
                $startLine = $i;
                $currentConflict = ['ours' => '', 'theirs' => '', 'side' => 'ours', 'startLine' => $i];
            } elseif ($inConflict && str_starts_with($line, '=======')) {
                $currentConflict['side'] = 'theirs';
            } elseif ($inConflict && str_starts_with($line, '>>>>>>>')) {
                $currentConflict['endLine'] = $i;
                $conflicts[] = $currentConflict;
                $inConflict = false;
                $currentConflict = null;
            } elseif ($inConflict) {
                $side = $currentConflict['side'];
                $currentConflict[$side] .= $line."\n";
            }
        }

        return $conflicts;
    }

    /**
     * Resolve conflict sections using AI, only sending the conflict parts.
     */
    private function resolveConflictsWithAi(
        string $path,
        string $contentWithMarkers,
        array $conflicts,
        ?int $userId = null,
    ): ?string {
        $provider = Setting::getValue('ai_provider', 'gemini', $userId);

        // Build a prompt with only the conflict sections
        $conflictText = '';
        foreach ($conflicts as $i => $c) {
            $num = $i + 1;
            $conflictText .= "--- Conflict #{$num} ---\n";
            $conflictText .= "<<<<<<< BASE (main branch)\n";
            $conflictText .= rtrim($c['ours'], "\n")."\n";
            $conflictText .= "=======\n";
            $conflictText .= rtrim($c['theirs'], "\n")."\n";
            $conflictText .= ">>>>>>> HEAD (PR branch)\n\n";
        }

        $prompt = <<<PROMPT
You are a code merge conflict resolver. The file "{$path}" has merge conflicts.
Below are ONLY the conflicting sections. Resolve each conflict by producing the correct merged code.

{$conflictText}
Instructions:
1. For each conflict, produce the resolved version that combines both sides correctly
2. Output ONLY the resolved code for each conflict, separated by "--- Resolution #N ---"
3. Do NOT include conflict markers (<<<, ===, >>>)
4. Do NOT include any explanations, just the code
5. Preserve indentation and formatting

Output:
PROMPT;

        try {
            $aiResponse = match ($provider) {
                'lmstudio' => $this->mergeViaLmStudio($prompt, $userId),
                default => $this->mergeViaGemini($prompt, $userId),
            };
        } catch (\Throwable $e) {
            Log::error('AI Merge: AI call failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $aiResponse) {
            return null;
        }

        // Parse AI resolutions
        $resolutions = $this->parseAiResolutions($aiResponse, count($conflicts));

        // Reconstruct the file by replacing conflict blocks with AI resolutions
        $lines = explode("\n", $contentWithMarkers);
        $result = [];
        $skipUntil = -1;

        foreach ($lines as $i => $line) {
            if ($i <= $skipUntil) {
                continue;
            }

            // Check if this line starts a conflict
            $conflictIndex = null;
            foreach ($conflicts as $ci => $c) {
                if ($c['startLine'] === $i) {
                    $conflictIndex = $ci;

                    break;
                }
            }

            if ($conflictIndex !== null && isset($resolutions[$conflictIndex])) {
                // Replace the conflict block with AI resolution
                $result[] = rtrim($resolutions[$conflictIndex], "\n");
                $skipUntil = $conflicts[$conflictIndex]['endLine'];
            } else {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Parse AI response into individual conflict resolutions.
     */
    private function parseAiResolutions(string $response, int $expectedCount): array
    {
        // Try to split by "--- Resolution #N ---" markers
        $parts = preg_split('/---\s*Resolution\s*#?\d+\s*---/i', $response);

        // Remove empty first element if present
        $parts = array_values(array_filter($parts, fn ($p) => trim($p) !== ''));

        if (count($parts) >= $expectedCount) {
            return array_map('trim', $parts);
        }

        // Fallback: if only 1 conflict, use entire response
        if ($expectedCount === 1) {
            return [trim($response)];
        }

        // Fallback: try splitting by blank lines
        $parts = preg_split('/\n{3,}/', $response);
        $parts = array_values(array_filter($parts, fn ($p) => trim($p) !== ''));

        return array_map('trim', $parts);
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

        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a code merge assistant. Output ONLY the merged file content, nothing else.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 16384,
            'stream' => true,
        ]);

        // Use raw cURL for SSE streaming — no overall timeout, just connect timeout
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 0, // No timeout — stream until done
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$fullText) {
                // Parse SSE lines
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        break;
                    }
                    $decoded = json_decode($json, true);
                    $delta = $decoded['choices'][0]['delta']['content'] ?? '';
                    $fullText .= $delta;
                }

                return strlen($data);
            },
        ]);

        $fullText = '';
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode >= 400) {
            throw new \RuntimeException("LM Studio streaming error ({$httpCode}): {$error}");
        }

        // Strip thinking tags
        $fullText = preg_replace('/<think>.*?<\/think>/s', '', $fullText);

        return trim($fullText) ?: null;
    }
}
