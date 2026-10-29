<?php

declare(strict_types=1);

namespace Votepit\Security\Turnstile;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Security\ClientIp;
use Votepit\Security\RateLimiter;

/**
 * Anti-automation challenge for public write endpoints (OWASP/BSI audit
 * remediation issue 20): idea creation, voting and commenting stay
 * unchallenged for ordinary users, but an IP that keeps tripping the
 * existing per-IP rate limits on these actions (RateLimitMiddleware::perIp())
 * is required to solve a Cloudflare Turnstile challenge before its next
 * write goes through.
 *
 * Suspicion counter: a SEPARATE rolling-window bucket
 * ("turnstile:suspicion:ip:<ip>"), bumped once per 429 via
 * RateLimitMiddleware's onLimitExceeded hook (see AppFactory wiring) —
 * deliberately independent of the underlying rate-limit buckets'
 * shorter windows, so a burst that gets rate-limited a few times in a row
 * still counts as suspicious even after the rate limit itself resets and
 * lets requests through again.
 *
 * Fail-secure-by-absence: when Turnstile isn't configured
 * (Config::turnstileConfigured() === false — the default), requiresChallenge()
 * always returns false and verify() is never called — a self-host install
 * that never sets this up behaves exactly as before this issue existed
 * (see Config::turnstileConfigured() class doc).
 *
 * Fail-secure on verification: any transport-level failure talking to
 * Cloudflare is treated as "challenge not passed" (TurnstileHttpClient::
 * verify() returning null), consistent with this project's fail-secure
 * default for security gates — unlike e.g. HibpHttpClient, which fails
 * OPEN because it's a defense-in-depth bonus check, not the gate itself.
 */
final readonly class TurnstileGuard
{
    private const BUCKET_PREFIX = 'turnstile:suspicion:ip:';

    public function __construct(
        private Config $config,
        private RateLimiter $limiter,
        private TurnstileHttpClient $http,
    ) {}

    public function isConfigured(): bool
    {
        return $this->config->turnstileConfigured();
    }

    /** The public site key the SPA needs to render the widget, or null if unconfigured. */
    public function siteKey(): ?string
    {
        return $this->config->turnstileConfigured() ? $this->config->turnstileSiteKey() : null;
    }

    /**
     * Bumps the suspicion counter for an IP — wired as RateLimitMiddleware's
     * onLimitExceeded hook for the guarded actions. A noop when Turnstile
     * isn't configured (no point tracking suspicion nothing will ever act
     * on) and fail-open on a DB error (same reasoning as RateLimiter itself
     * — this must never turn an already-decided 429 into a 500).
     */
    public function recordSuspicion(string $ip): void
    {
        if (!$this->config->turnstileConfigured()) {
            return;
        }

        try {
            $this->limiter->count(self::BUCKET_PREFIX . $ip, $this->config->turnstileSuspicionWindowSeconds());
        } catch (DbalException) {
            // Fail-open, see class doc.
        }
    }

    /**
     * Whether the given IP currently must solve a Turnstile challenge
     * before its next write on a guarded action. Read-only (never
     * increments) — repeated calls within the same request don't inflate
     * the counter.
     */
    public function requiresChallenge(string $ip): bool
    {
        if (!$this->config->turnstileConfigured()) {
            return false;
        }

        try {
            $count = $this->limiter->peek(self::BUCKET_PREFIX . $ip, $this->config->turnstileSuspicionWindowSeconds());
        } catch (DbalException) {
            return false; // fail-open on DB error, same reasoning as recordSuspicion()
        }

        return $count >= $this->config->turnstileSuspicionThreshold();
    }

    /**
     * Verifies a submitted Turnstile token. Returns false immediately
     * (no network call) for an empty token or when Turnstile isn't
     * configured.
     */
    /**
     * $expectedAction (ADR 0006 §3): when given, siteverify must also report
     * exactly this widget `action`, so a token solved for another form cannot
     * be replayed here. Null keeps the original behaviour for every existing
     * caller.
     */
    public function verify(string $token, ?string $ip, ?string $expectedAction = null): bool
    {
        if (!$this->config->turnstileConfigured() || $token === '') {
            return false;
        }

        $result = $this->http->verify($this->config->turnstileSecretKey(), $token, $ip);
        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            return false;
        }

        return $expectedAction === null || ($result['action'] ?? null) === $expectedAction;
    }

    /**
     * Request-based convenience wrapper for requiresChallenge() — resolves
     * the client IP the same way RateLimitMiddleware::perIp() does
     * (Config::trustCloudflareIp), so the guarded actions don't each need
     * their own trustCloudflareIp wiring.
     */
    public function requiresChallengeFor(ServerRequestInterface $request): bool
    {
        $ip = ClientIp::resolve($request, $this->config->trustCloudflareIp);

        return $ip !== null && $this->requiresChallenge($ip);
    }

    /** Request-based convenience wrapper for verify() — see requiresChallengeFor(). */
    public function verifyFor(ServerRequestInterface $request, string $token): bool
    {
        return $this->verify($token, ClientIp::resolve($request, $this->config->trustCloudflareIp));
    }
}
