<?php

declare(strict_types=1);

namespace Votepit\Logging;

use Votepit\Security\RateLimiter;

/**
 * Sampled `authz.denied` logging (OWASP/BSI audit remediation issue 10).
 *
 * A bare AuditLogger::log() call on every AuthZMiddleware denial would let
 * scanner/bot traffic flood the audit log — a single scanner hammering one
 * protected route produces one denial per request, unbounded. This wraps
 * AuditLogger with a fixed-window sample cap (same RateLimiter primitive
 * already used for login/magic-link throttling elsewhere): once a bucket
 * exceeds the cap within the window, further denials in that window are
 * silently dropped from the log (the request itself is still denied by
 * AuthZMiddleware regardless — this only samples the LOG WRITE, never the
 * authorization decision).
 *
 * $rateLimiter is optional (null in the DB-less smoke-test app) — without
 * one, every denial is logged unsampled (acceptable: the smoke app has no
 * real traffic to flood the log with).
 */
final readonly class AuthzDenialLogger
{
    private const SAMPLE_LIMIT  = 20;
    private const SAMPLE_WINDOW = 60;

    public function __construct(
        private AuditLogger $audit,
        private ?RateLimiter $rateLimiter = null,
    ) {}

    /** @param array<string, mixed> $context */
    public function logDenied(array $context, string $sampleKey): void
    {
        if (
            $this->rateLimiter instanceof RateLimiter
            && !$this->rateLimiter->hit("authz.denied:{$sampleKey}", self::SAMPLE_LIMIT, self::SAMPLE_WINDOW)
        ) {
            return;
        }

        $this->audit->log('authz.denied', $context);
    }
}
