<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CodeReviewService
{
    /**
     * Get the configured AI provider: 'gemini' or 'lmstudio'.
     */
    private function getProvider(): string
    {
        return Setting::getValue('ai_provider', 'gemini');
    }

    /**
     * Review a PR diff using the configured AI provider.
     */
    public function reviewDiff(string $diff, array $config = []): array
    {
        $provider = $this->getProvider();

        Log::info("Running code review via {$provider}");

        return match ($provider) {
            'lmstudio' => $this->reviewViaLmStudio($diff, $config),
            default => $this->reviewViaGemini($diff, $config),
        };
    }

    // ──────────────────────────────────────────────────
    //  Gemini Provider
    // ──────────────────────────────────────────────────

    private function reviewViaGemini(string $diff, array $config = []): array
    {
        $apiKey = Setting::getValue('gemini_api_key', config('services.gemini.api_key', ''));
        $model = Setting::getValue('gemini_model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $prompt = $this->buildFullPrompt($diff, $config);

        $response = Http::timeout(120)->post($url, [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->getResponseSchema(),
            ],
        ]);

        if ($response->failed()) {
            Log::error('Gemini API failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException("Gemini API error ({$response->status()}): {$response->body()}");
        }

        $text = $response->json('candidates.0.content.parts.0.text', '{}');

        return $this->parseJsonResponse($text);
    }

    // ──────────────────────────────────────────────────
    //  LM Studio Provider (OpenAI-compatible API)
    // ──────────────────────────────────────────────────

    private function reviewViaLmStudio(string $diff, array $config = []): array
    {
        $baseUrl = Setting::getValue('lmstudio_base_url', 'http://localhost:1234');
        $model = Setting::getValue('lmstudio_model', 'default');

        $baseUrl = rtrim($baseUrl, '/');
        $url = "{$baseUrl}/v1/chat/completions";

        $systemPrompt = $this->buildSystemPrompt($config);
        $userPrompt = $this->buildReviewPrompt($diff);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt . "\n\nIMPORTANT: You MUST respond with ONLY a valid JSON object. No markdown, no explanation, no code fences. Just the raw JSON."],
            ],
            'temperature' => 0.3,
            'max_tokens' => 4096,
        ];

        $response = Http::timeout(180)->post($url, $payload);

        if ($response->failed()) {
            Log::error('LM Studio API failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException("LM Studio API error ({$response->status()}): {$response->body()}");
        }

        $text = $response->json('choices.0.message.content', '{}');

        Log::debug('LM Studio response content', [
            'length' => strlen($text),
            'preview' => substr($text, 0, 500),
        ]);

        $parsed = $this->parseJsonResponse($text);
        $parsed['raw_output'] = $text;

        return $parsed;
    }

    // ──────────────────────────────────────────────────
    //  Prompt Building
    // ──────────────────────────────────────────────────

    private function buildFullPrompt(string $diff, array $config = []): string
    {
        return $this->buildSystemPrompt($config) . "\n\n" . $this->buildReviewPrompt($diff);
    }

    private function buildSystemPrompt(array $config = []): string
    {
        $focusAreas = $config['focus_areas'] ?? [
            'security vulnerabilities',
            'performance issues',
            'logic errors and bugs',
            'code style and best practices',
            'error handling',
        ];
        $focusList = implode("\n- ", $focusAreas);

        return <<<PROMPT
You are an expert senior code reviewer. Review pull request diffs and provide actionable feedback.

Focus areas:
- {$focusList}

Rules:
1. Only comment on issues you are confident about.
2. Specify the exact file path and line number from the diff.
3. Severity: critical (must fix), warning (should fix), suggestion (nice to have), info (FYI).
4. Category: security, performance, bug, style, error-handling, documentation, testing.
5. Be concise but specific. Include the problematic code snippet.
6. Provide a clear fix suggestion for each finding.

Respond ONLY with valid JSON in this exact format:
{
  "summary": "Brief overall summary",
  "overall_quality": "good|acceptable|needs-improvement|poor",
  "findings": [
    {
      "file_path": "path/to/file.ext",
      "line_number": 42,
      "severity": "critical|warning|suggestion|info",
      "category": "security|performance|bug|style|error-handling|documentation|testing",
      "title": "Short issue title",
      "body": "Detailed explanation of the issue",
      "suggestion": "How to fix it"
    }
  ]
}
PROMPT;
    }

    private function buildReviewPrompt(string $diff): string
    {
        // Truncate very large diffs
        $maxLen = 100000;
        if (strlen($diff) > $maxLen) {
            $diff = substr($diff, 0, $maxLen) . "\n\n... [DIFF TRUNCATED] ...";
        }

        return "Please review the following pull request diff:\n\n```diff\n{$diff}\n```";
    }

    // ──────────────────────────────────────────────────
    //  Response Parsing & Formatting
    // ──────────────────────────────────────────────────

    private function parseJsonResponse(string $text): array
    {
        // Strip Qwen-style <think>...</think> reasoning tags
        $text = trim($text);
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        // Try to extract JSON object if surrounded by other text
        if (! str_starts_with($text, '{')) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $text = substr($text, $start, $end - $start + 1);
            }
        }

        try {
            $parsed = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

            return [
                'summary' => $parsed['summary'] ?? '',
                'overall_quality' => $parsed['overall_quality'] ?? 'acceptable',
                'findings' => $parsed['findings'] ?? [],
            ];
        } catch (\JsonException $e) {
            Log::warning('Failed to parse AI review response', ['text' => substr($text, 0, 500), 'error' => $e->getMessage()]);

            return ['summary' => '', 'overall_quality' => 'unknown', 'findings' => []];
        }
    }

    private function getResponseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'summary' => ['type' => 'STRING'],
                'overall_quality' => ['type' => 'STRING'],
                'findings' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'file_path' => ['type' => 'STRING'],
                            'line_number' => ['type' => 'INTEGER'],
                            'severity' => ['type' => 'STRING'],
                            'category' => ['type' => 'STRING'],
                            'title' => ['type' => 'STRING'],
                            'body' => ['type' => 'STRING'],
                            'suggestion' => ['type' => 'STRING'],
                        ],
                        'required' => ['file_path', 'severity', 'category', 'title', 'body'],
                    ],
                ],
            ],
            'required' => ['summary', 'overall_quality', 'findings'],
        ];
    }

    /**
     * Format findings as GitHub review comments.
     */
    public function formatReviewAsComments(array $findings): array
    {
        return array_map(function ($finding) {
            $icon = match ($finding['severity'] ?? 'info') {
                'critical' => '🚨', 'warning' => '⚠️', 'suggestion' => '💡', default => 'ℹ️',
            };

            $body = "{$icon} **{$finding['title']}** [{$finding['severity']}]\n\n{$finding['body']}\n\n";
            if (!empty($finding['suggestion'])) {
                $body .= "**Suggested fix:**\n{$finding['suggestion']}";
            }

            return [
                'path' => $finding['file_path'],
                'line' => $finding['line_number'] ?? null,
                'body' => $body,
                'severity' => $finding['severity'] ?? 'info',
                'category' => $finding['category'] ?? 'general',
            ];
        }, $findings);
    }

    /**
     * Build a fix prompt for Jules based on review findings.
     */
    public function buildJulesFixPrompt(array $findings, int $prNumber): string
    {
        $issueList = '';
        $i = 1;
        foreach ($findings as $f) {
            if (!in_array($f['severity'] ?? '', ['critical', 'warning']))
                continue;
            $issueList .= "\n{$i}. [{$f['severity']}] {$f['file_path']}";
            if (!empty($f['line_number']))
                $issueList .= " (line {$f['line_number']})";
            $issueList .= ": {$f['title']}\n   Problem: {$f['body']}\n";
            if (!empty($f['suggestion']))
                $issueList .= "   Fix: {$f['suggestion']}\n";
            $i++;
        }

        return "Code review found issues in PR #{$prNumber}. Fix the following:\n{$issueList}\n\nFix all issues, maintain code style, don't introduce new issues.";
    }

    /**
     * Generate a review summary for GitHub PR comment.
     */
    public function generateReviewSummary(array $findings, string $overallQuality = 'acceptable'): string
    {
        $counts = ['critical' => 0, 'warning' => 0, 'suggestion' => 0, 'info' => 0];
        foreach ($findings as $f) {
            $s = $f['severity'] ?? 'info';
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }

        $emoji = match ($overallQuality) {
            'good' => '✅', 'acceptable' => '🟡', 'needs-improvement' => '🟠', 'poor' => '🔴', default => '🔵',
        };

        $provider = $this->getProvider() === 'lmstudio' ? 'LM Studio' : 'Gemini';

        $s = "## 🤖 Automated Code Review\n\n";
        $s .= "{$emoji} **Overall Quality**: {$overallQuality}\n\n";
        $s .= "| Severity | Count |\n|----------|-------|\n";
        $s .= "| 🚨 Critical | {$counts['critical']} |\n";
        $s .= "| ⚠️ Warning | {$counts['warning']} |\n";
        $s .= "| 💡 Suggestion | {$counts['suggestion']} |\n";
        $s .= "| ℹ️ Info | {$counts['info']} |\n\n";

        if ($counts['critical'] > 0 || $counts['warning'] > 0) {
            $s .= "> 🔧 **Auto-fix triggered** — Jules will create a new PR to address critical/warning issues.\n";
        } else {
            $s .= "> ✅ No critical issues found. This PR looks good!\n";
        }

        $s .= "\n---\n*Powered by Jules + {$provider} Code Review Bot*";
        return $s;
    }

    /**
     * Determine if auto-fix should be triggered.
     */
    public function shouldAutoFix(array $findings): bool
    {
        $threshold = Setting::getValue('auto_fix_threshold', 'warning');

        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'info';
            if ($threshold === 'critical' && $sev === 'critical')
                return true;
            if ($threshold === 'warning' && in_array($sev, ['critical', 'warning']))
                return true;
        }

        return false;
    }
}
