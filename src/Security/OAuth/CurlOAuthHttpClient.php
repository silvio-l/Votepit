<?php

declare(strict_types=1);

namespace Votepit\Security\OAuth;

/**
 * curl-based OAuthHttpClient (this codebase has no Guzzle/PSR HTTP client
 * dependency — see Votepit\Telemetry\CurlMatomoEventTracker for the same
 * pattern). Short, fixed timeouts; redirects are never followed
 * (CURLOPT_FOLLOWLOCATION disabled — a provider response redirecting the
 * token/userinfo call somewhere else is treated as a failure, not chased).
 *
 * Unlike CurlMatomoEventTracker, a transport-level failure is NOT swallowed
 * here — it becomes an OAuthHttpException, which OAuthCallbackAction turns
 * into a generic login failure (never a provider-error leak to the client).
 * A completed HTTP response (even 4xx/5xx) is returned normally — the
 * caller decides what a non-2xx status means for that specific call.
 */
final readonly class CurlOAuthHttpClient implements OAuthHttpClient
{
    private const TIMEOUT_MS = 5000;

    public function postForm(string $url, array $params, array $headers = []): array
    {
        return $this->request($url, http_build_query($params), $headers);
    }

    public function getJson(string $url, array $headers = []): array
    {
        return $this->request($url, null, $headers);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: array<array-key, mixed>}
     */
    private function request(string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OAuthHttpException('oauth: curl_init failed');
        }

        $options = [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS        => self::TIMEOUT_MS,
            CURLOPT_FOLLOWLOCATION    => false,
            // PHP's curl extension sends NO User-Agent by default (unlike
            // the `curl` binary) — GitHub's API rejects every request
            // without one outright ("Request forbidden by administrative
            // rules", a plain-text 403 body, not JSON — silently decoded to
            // an empty body here and surfaced as a generic profile-fetch
            // failure). Found live on staging 2026-09-07 testing real
            // GitHub credentials end-to-end: /user and /user/emails both
            // 403'd this way, every GitHub login failed at the profile
            // step. Google's endpoints never required this, so the gap was
            // GitHub-specific and easy to miss.
            CURLOPT_USERAGENT         => 'Votepit-OAuth',
            CURLOPT_HTTPHEADER        => array_merge(['Accept: application/json'], $headers),
        ];
        if ($body !== null) {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new OAuthHttpException('oauth: request failed: ' . $error);
        }

        /** @var int $status */
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var mixed $decoded */
        $decoded = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }
}
