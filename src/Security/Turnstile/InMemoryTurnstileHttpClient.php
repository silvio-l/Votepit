<?php

declare(strict_types=1);

namespace Votepit\Security\Turnstile;

/**
 * Test double for TurnstileHttpClient (mirrors Votepit\Security\Password\
 * InMemoryHibpHttpClient) — no real network calls. By default every token
 * is rejected (fail-secure default matching CurlTurnstileHttpClient's
 * "unknown → null" behavior); stubSuccess() registers a token as passing.
 */
final class InMemoryTurnstileHttpClient implements TurnstileHttpClient
{
    /** @var array<string, string|null> token => widget action reported by siteverify */
    private array $successTokens = [];

    /** @var array<string, true> */
    private array $transportFailures = [];

    /** $action: the widget `action` siteverify reports back for this token (null = none). */
    public function stubSuccess(string $token, ?string $action = null): void
    {
        $this->successTokens[$token] = $action;
    }

    /** Simulates an unreachable siteverify endpoint (the real client then returns null). */
    public function stubTransportFailure(string $token): void
    {
        $this->transportFailures[$token] = true;
    }

    public function verify(string $secretKey, string $token, ?string $remoteIp): ?array
    {
        if (isset($this->transportFailures[$token])) {
            return null;
        }
        if (!array_key_exists($token, $this->successTokens)) {
            return ['success' => false];
        }

        $action = $this->successTokens[$token];

        return $action === null ? ['success' => true] : ['success' => true, 'action' => $action];
    }
}
