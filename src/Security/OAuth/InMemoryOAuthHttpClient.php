<?php

declare(strict_types=1);

namespace Votepit\Security\OAuth;

/**
 * Test double for OAuthHttpClient (mirrors Votepit\Telemetry\
 * InMemoryMatomoEventTracker) — returns canned responses per URL, no real
 * network calls. stub() registers a response, stubFailure() registers a
 * transport-level failure (OAuthHttpException) for the given URL.
 */
final class InMemoryOAuthHttpClient implements OAuthHttpClient
{
    /** @var array<string, array{status: int, body: array<array-key, mixed>}> */
    private array $responses = [];

    /** @var array<string, true> */
    private array $failures = [];

    /** @param array{status: int, body: array<array-key, mixed>} $response */
    public function stub(string $url, array $response): void
    {
        $this->responses[$url] = $response;
    }

    public function stubFailure(string $url): void
    {
        $this->failures[$url] = true;
    }

    public function postForm(string $url, array $params, array $headers = []): array
    {
        return $this->respond($url);
    }

    public function getJson(string $url, array $headers = []): array
    {
        return $this->respond($url);
    }

    /** @return array{status: int, body: array<array-key, mixed>} */
    private function respond(string $url): array
    {
        if (isset($this->failures[$url])) {
            throw new OAuthHttpException("oauth: stubbed transport failure for {$url}");
        }

        return $this->responses[$url] ?? throw new OAuthHttpException("oauth: no stub registered for {$url}");
    }
}
