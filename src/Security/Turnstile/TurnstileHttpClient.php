<?php

declare(strict_types=1);

namespace Votepit\Security\Turnstile;

/**
 * Outbound HTTP seam for Cloudflare Turnstile's siteverify endpoint —
 * mirrors Votepit\Security\Password\HibpHttpClient's interface+curl-impl+
 * in-memory-fake pattern (this codebase has no Guzzle/PSR HTTP client
 * dependency, curl only).
 */
interface TurnstileHttpClient
{
    /**
     * POSTs the verification request. Returns the decoded JSON response
     * body, or null on any transport-level failure or non-2xx status —
     * TurnstileGuard treats null the same as an explicit failure
     * (fail-secure: an unreachable Turnstile must never be treated as "the
     * challenge passed").
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $secretKey, string $token, ?string $remoteIp): ?array;
}
