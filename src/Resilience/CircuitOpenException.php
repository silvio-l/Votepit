<?php

declare(strict_types=1);

namespace Votepit\Resilience;

/**
 * Thrown by a CircuitBreaker-wrapped client instead of attempting the real
 * outbound call while its circuit is open — the "respond fast instead of
 * timing out again" half of OWASP/BSI audit remediation issue 13.
 */
final class CircuitOpenException extends \RuntimeException
{
    public function __construct(string $service)
    {
        parent::__construct(sprintf('Circuit breaker for "%s" is open — skipping the call.', $service));
    }
}
