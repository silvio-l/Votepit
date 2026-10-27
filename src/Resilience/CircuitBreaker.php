<?php

declare(strict_types=1);

namespace Votepit\Resilience;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * DB-backed circuit breaker (OWASP/BSI audit remediation issue 13) for
 * outbound calls to a critical external dependency (SMTP relay, payment
 * provider API, Google Translate). One row per service in `circuit_breakers`
 * (migrations/0067) — same "small counter table, UPSERT-style" shape as
 * RateLimiter's `rate_limits`, just tracking consecutive failures instead
 * of a request count.
 *
 * State machine:
 *   - closed:    consecutive_failures below threshold — calls go through.
 *   - open:      threshold reached, opened_at set, cooldown not yet
 *                elapsed — isOpen() returns true, callers fail fast
 *                instead of attempting (and potentially timing out on) the
 *                real call.
 *   - half-open: cooldown elapsed — isOpen() returns false again so the
 *                NEXT call is tried; recordSuccess() closes the circuit,
 *                recordFailure() re-opens it (refreshes opened_at).
 *
 * Best-effort like RateLimiter: a lost race under concurrent requests can
 * at most cause one extra real call or one extra open cycle, never a
 * crash — DB failures propagate (same as RateLimiter), callers decide
 * whether that itself should fail open or closed.
 */
final readonly class CircuitBreaker
{
    public function __construct(private Connection $conn) {}

    /** @throws DbalException */
    public function isOpen(string $service, int $cooldownSeconds): bool
    {
        $row = $this->conn->fetchAssociative(
            'SELECT opened_at FROM circuit_breakers WHERE service = :service',
            ['service' => $service],
        );

        if ($row === false || $row['opened_at'] === null) {
            return false;
        }

        $openedAt = new \DateTimeImmutable((string) $row['opened_at']);
        $ageSeconds = (new \DateTimeImmutable())->getTimestamp() - $openedAt->getTimestamp();

        return $ageSeconds < $cooldownSeconds;
    }

    /**
     * Call after a failed attempt. Once $threshold consecutive failures are
     * reached, (re-)opens the circuit (sets/refreshes opened_at).
     *
     * @throws DbalException
     */
    public function recordFailure(string $service, int $threshold): void
    {
        $row = $this->conn->fetchAssociative(
            'SELECT consecutive_failures FROM circuit_breakers WHERE service = :service',
            ['service' => $service],
        );

        $failures = $row === false ? 1 : (int) $row['consecutive_failures'] + 1;
        $open     = $failures >= $threshold;
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT INTO circuit_breakers (service, consecutive_failures, opened_at, updated_at)
                 VALUES (:service, :failures, :opened_at, :now)
                 ON DUPLICATE KEY UPDATE consecutive_failures = :failures2, opened_at = :opened_at2, updated_at = :now2',
                [
                    'service'    => $service,
                    'failures'   => $failures,
                    'opened_at'  => $open ? $now : null,
                    'now'        => $now,
                    'failures2'  => $failures,
                    'opened_at2' => $open ? $now : null,
                    'now2'       => $now,
                ],
            );
            return;
        }

        $this->conn->executeStatement(
            'INSERT OR REPLACE INTO circuit_breakers (service, consecutive_failures, opened_at, updated_at)
             VALUES (:service, :failures, :opened_at, :now)',
            ['service' => $service, 'failures' => $failures, 'opened_at' => $open ? $now : null, 'now' => $now],
        );
    }

    /** Call after a successful attempt — closes the circuit. @throws DbalException */
    public function recordSuccess(string $service): void
    {
        $this->conn->delete('circuit_breakers', ['service' => $service]);
    }
}
