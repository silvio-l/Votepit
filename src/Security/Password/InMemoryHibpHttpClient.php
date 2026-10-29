<?php

declare(strict_types=1);

namespace Votepit\Security\Password;

/**
 * Test double for HibpHttpClient (mirrors Votepit\Security\OAuth\
 * InMemoryOAuthHttpClient) — returns canned responses per URL, no real
 * network calls. stub() registers a response body, stubFailure() registers
 * a transport failure (get() returns null) for the given URL. An
 * unregistered URL also returns null (fail-open default — a test that
 * doesn't care about HIBP at all doesn't need to stub anything).
 */
final class InMemoryHibpHttpClient implements HibpHttpClient
{
    /** @var array<string, string> */
    private array $responses = [];

    /** @var array<string, true> */
    private array $failures = [];

    public function stub(string $url, string $body): void
    {
        $this->responses[$url] = $body;
    }

    public function stubFailure(string $url): void
    {
        $this->failures[$url] = true;
    }

    public function get(string $url): ?string
    {
        if (isset($this->failures[$url])) {
            return null;
        }

        return $this->responses[$url] ?? null;
    }
}
