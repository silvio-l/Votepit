<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

/**
 * Snapshot of the DBMS operating parameters checked by
 * bin/check-db-health.php (OWASP/BSI audit remediation issue 13). Any field
 * left null means that particular metric could not be collected this run
 * (e.g. slow-query rate needs a previous sample first) — DbHealthChecker
 * skips a null metric's threshold instead of treating it as a violation.
 */
final readonly class DbHealthMetrics
{
    public function __construct(
        public ?float $connectionsUsedPercent = null,
        public ?float $dbSizeMb = null,
        public ?float $diskFreeMb = null,
        public ?float $slowQueryRatePercent = null,
    ) {}
}
