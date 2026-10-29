<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

interface ErrorReporter
{
    public function report(\Throwable $exception): void;

    /**
     * Mirrors a critical, non-exceptional security event (OWASP/BSI audit
     * remediation issue 12) — deliberately reserved for the small,
     * hand-picked subset that's actually worth an alert (role changes,
     * operator account/board deletion, password reset, login failures over
     * threshold), not every AuditLogger::log() call, to respect the shared
     * Sentry free-tier quota (see docs/README.md#sentry).
     *
     * @param 'info'|'warning' $level
     * @param array<string, mixed> $context Never raw PII — email_hmac/uid only.
     */
    public function reportSecurityEvent(string $message, string $level, array $context): void;
}
