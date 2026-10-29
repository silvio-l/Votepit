<?php

declare(strict_types=1);

namespace Votepit\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use Votepit\Monitoring\DbHealthChecker;
use Votepit\Monitoring\DbHealthMetrics;
use Votepit\Monitoring\DbHealthThresholds;

/** OWASP/BSI audit remediation issue 13: DBMS parameter monitoring thresholds. */
final class DbHealthCheckerTest extends TestCase
{
    public function test_reports_no_violations_when_every_metric_is_within_bounds(): void
    {
        $checker = new DbHealthChecker();

        $violations = $checker->evaluate(
            new DbHealthMetrics(connectionsUsedPercent: 10.0, dbSizeMb: 100.0, diskFreeMb: 5000.0, slowQueryRatePercent: 0.1),
            new DbHealthThresholds(),
        );

        self::assertSame([], $violations);
    }

    public function test_detects_a_simulated_connections_threshold_breach(): void
    {
        $checker = new DbHealthChecker();

        $violations = $checker->evaluate(
            new DbHealthMetrics(connectionsUsedPercent: 95.0),
            new DbHealthThresholds(maxConnectionsUsedPercent: 80.0),
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('connections utilization', $violations[0]);
    }

    public function test_detects_a_simulated_db_size_threshold_breach(): void
    {
        $checker = new DbHealthChecker();

        $violations = $checker->evaluate(
            new DbHealthMetrics(dbSizeMb: 4096.0),
            new DbHealthThresholds(maxDbSizeMb: 2048.0),
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('DB size', $violations[0]);
    }

    public function test_detects_a_simulated_low_diskspace_breach(): void
    {
        $checker = new DbHealthChecker();

        $violations = $checker->evaluate(
            new DbHealthMetrics(diskFreeMb: 100.0),
            new DbHealthThresholds(minDiskFreeMb: 500.0),
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('disk space', $violations[0]);
    }

    public function test_detects_a_simulated_slow_query_rate_breach(): void
    {
        $checker = new DbHealthChecker();

        $violations = $checker->evaluate(
            new DbHealthMetrics(slowQueryRatePercent: 12.0),
            new DbHealthThresholds(maxSlowQueryRatePercent: 5.0),
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('Slow-query rate', $violations[0]);
    }

    public function test_null_metrics_are_skipped_rather_than_treated_as_violations(): void
    {
        $checker = new DbHealthChecker();

        $violations = $checker->evaluate(new DbHealthMetrics(), new DbHealthThresholds());

        self::assertSame([], $violations);
    }

    public function test_reports_every_breached_metric_at_once(): void
    {
        $checker = new DbHealthChecker();

        $violations = $checker->evaluate(
            new DbHealthMetrics(connectionsUsedPercent: 99.0, dbSizeMb: 9999.0, diskFreeMb: 1.0, slowQueryRatePercent: 50.0),
            new DbHealthThresholds(),
        );

        self::assertCount(4, $violations);
    }
}
