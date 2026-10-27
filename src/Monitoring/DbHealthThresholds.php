<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

/**
 * Alarm thresholds for DbHealthChecker (OWASP/BSI audit remediation issue
 * 13). Defaults are deliberately conservative for a small shared-hosting
 * MySQL instance (same "shared hosting" theme as MailVolumeMonitor) —
 * operators on a bigger plan can pass their own via
 * bin/check-db-health.php's constructor call.
 */
final readonly class DbHealthThresholds
{
    public function __construct(
        public float $maxConnectionsUsedPercent = 80.0,
        public float $maxDbSizeMb = 2048.0,
        public float $minDiskFreeMb = 500.0,
        public float $maxSlowQueryRatePercent = 5.0,
    ) {}
}
