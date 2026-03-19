<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubAppService
{
    /**
     * Get an installation access token for the GitHub App.
     * Tokens are cached for 50 minutes (they expire after 60).
     */
    public function getInstallationToken(?int $userId = null): ?string
    {
        $appId = Setting::getValue('github_app_id', '', $userId);
        $privateKey = Setting::getValue('github_app_private_key', '', $userId);
        $installationId = Setting::getValue('github_app_installation_id', '', $userId);

        if (empty($appId) || empty($privateKey) || empty($installationId)) {
            return null; // GitHub App not configured, fall back to PAT
        }

        $cacheKey = "github_app_token_{$installationId}";

        return Cache::remember($cacheKey, 3000, function () use ($appId, $privateKey, $installationId) {
            $jwt = $this->generateJwt($appId, $privateKey);

            if (! $jwt) {
                return null;
            }

            return $this->exchangeForInstallationToken($jwt, $installationId);
        });
    }

    /**
     * Generate a JWT for GitHub App authentication.
     * JWT is valid for 10 minutes (GitHub max).
     */
    private function generateJwt(string $appId, string $privateKey): ?string
    {
        try {
            $now = time();

            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ]));

            $payload = $this->base64UrlEncode(json_encode([
                'iat' => $now - 60,       // Issued at (60s in the past for clock drift)
                'exp' => $now + (10 * 60), // Expires in 10 minutes
                'iss' => $appId,           // GitHub App ID
            ]));

            $dataToSign = "{$header}.{$payload}";

            // Sign with RS256
            $privateKeyResource = openssl_pkey_get_private($privateKey);
            if (! $privateKeyResource) {
                Log::error('GitHub App: Invalid private key');

                return null;
            }

            $signature = '';
            openssl_sign($dataToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);

            return "{$header}.{$payload}.{$this->base64UrlEncode($signature)}";
        } catch (\Throwable $e) {
            Log::error('GitHub App: JWT generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Exchange a JWT for an installation access token.
     */
    private function exchangeForInstallationToken(string $jwt, string $installationId): ?string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$jwt}",
                'Accept' => 'application/vnd.github.v3+json',
            ])->post("https://api.github.com/app/installations/{$installationId}/access_tokens");

            if ($response->failed()) {
                Log::error('GitHub App: Failed to get installation token', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Clear cache so next attempt retries
                Cache::forget("github_app_token_{$installationId}");

                return null;
            }

            $token = $response->json('token');
            Log::info('GitHub App: Installation token acquired', [
                'installation' => $installationId,
                'expires_at' => $response->json('expires_at'),
            ]);

            return $token;
        } catch (\Throwable $e) {
            Log::error('GitHub App: Token exchange failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Clear the cached installation token (useful after errors).
     */
    public function clearTokenCache(?int $userId = null): void
    {
        $installationId = Setting::getValue('github_app_installation_id', '', $userId);
        if ($installationId) {
            Cache::forget("github_app_token_{$installationId}");
        }
    }

    /**
     * Test the GitHub App connection.
     */
    public function testConnection(?int $userId = null): array
    {
        $token = $this->getInstallationToken($userId);

        if (! $token) {
            return ['success' => false, 'message' => 'Could not acquire installation token'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/vnd.github.v3+json',
            ])->get('https://api.github.com/installation/repositories', ['per_page' => 1]);

            if ($response->successful()) {
                $total = $response->json('total_count', 0);
                $rateLimit = $response->header('X-RateLimit-Limit');
                $remaining = $response->header('X-RateLimit-Remaining');

                return [
                    'success' => true,
                    'message' => "Connected! {$total} repositories accessible. Rate limit: {$remaining}/{$rateLimit}",
                ];
            }

            return ['success' => false, 'message' => "API error: {$response->status()}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
