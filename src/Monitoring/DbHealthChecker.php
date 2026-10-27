<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

/**
 * Pure threshold evaluation for bin/check-db-health.php (OWASP/BSI audit
 * remediation issue 13) — deliberately separated from the MySQL-specific
 * metric collection (SHOW STATUS / information_schema, only meaningful
 * against a real MySQL server) so the threshold logic itself stays unit-
 * testable without a MySQL fixture.
 */
final class DbHealthChecker
{
    /** @return list<string> human-readable violation messages, empty if every collected metric is within bounds */
    public function evaluate(DbHealthMetrics $metrics, DbHealthThresholds $thresholds): array
    {
        $violations = [];

        if ($metrics->connectionsUsedPercent !== null && $metrics->connectionsUsedPercent > $thresholds->maxConnectionsUsedPercent) {
            $violations[] = sprintf(
                'DB connections utilization %.1f%% exceeds threshold %.1f%%',
                $metrics->connectionsUsedPercent,
                $thresholds->maxConnectionsUsedPercent,
            );
        }

        if ($metrics->dbSizeMb !== null && $metrics->dbSizeMb > $thresholds->maxDbSizeMb) {
            $violations[] = sprintf(
                'DB size %.1f MB exceeds threshold %.1f MB',
                $metrics->dbSizeMb,
                $thresholds->maxDbSizeMb,
            );
        }

        if ($metrics->diskFreeMb !== null && $metrics->diskFreeMb < $thresholds->minDiskFreeMb) {
            $violations[] = sprintf(
                'Free disk space %.1f MB is below threshold %.1f MB',
                $metrics->diskFreeMb,
                $thresholds->minDiskFreeMb,
            );
        }

        if ($metrics->slowQueryRatePercent !== null && $metrics->slowQueryRatePercent > $thresholds->maxSlowQueryRatePercent) {
            $violations[] = sprintf(
                'Slow-query rate %.1f%% exceeds threshold %.1f%%',
                $metrics->slowQueryRatePercent,
                $thresholds->maxSlowQueryRatePercent,
            );
        }

        return $violations;
    }
}
