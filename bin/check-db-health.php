#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — DBMS operating-parameter monitor (OWASP/BSI audit remediation
 * issue 13): connections utilization, DB (tablespace) size, free diskspace,
 * and slow-query rate, each checked against a conservative default
 * threshold (DbHealthThresholds) — a shared-hosting MySQL instance is
 * expected to stay well within these; a breach signals the operator should
 * look before the instance actually falls over.
 *
 * Metric collection (this script, MySQL-specific — SHOW STATUS/VARIABLES,
 * information_schema, disk_free_space()) is intentionally kept separate
 * from the pure threshold evaluation (Votepit\Monitoring\DbHealthChecker),
 * which is unit-tested directly (DbHealthCheckerTest) without needing a
 * real MySQL fixture. Skips cleanly (exit 0) against a non-MySQL connection
 * (SQLite test/dev setups) — nothing to check there.
 *
 * Slow-query RATE needs a delta between two samples (Slow_queries and
 * Questions are cumulative server counters since the last MySQL restart) —
 * the raw counters from this run are persisted in this job's own
 * cron_heartbeats.detail (JSON) so the NEXT run can compute the delta; the
 * very first run (or the first run after a MySQL restart resets the
 * counters) has no previous sample yet and skips that one check.
 *
 * IMPORTANT — no cron/scheduler is part of this script itself. It is
 * cron-callable (exit code 0 = healthy, 1 = threshold breached or a check
 * itself failed) and is registered as a managed job (CoreCronJobRegistry),
 * dispatched like any other job in bin/run-due-crons.php.
 *
 * Usage:
 *   php bin/check-db-health.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Votepit\Monitoring\DbHealthChecker;
use Votepit\Monitoring\DbHealthMetrics;
use Votepit\Monitoring\DbHealthThresholds;
use Votepit\Persistence\CronHeartbeatRepository;

const JOB_NAME = 'check-db-health';

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
    $conn   = \Votepit\Persistence\ConnectionFactory::create($config);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}

$reporter = $config->sentryDsn !== ''
    ? new \Votepit\Monitoring\SentryErrorReporter($config->sentryDsn, $config->env)
    : new \Votepit\Monitoring\NullErrorReporter();

$heartbeats = new CronHeartbeatRepository($conn);

if (!$conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
    echo "DB health checks are MySQL-specific — skipping (not a MySQL connection).\n";
    $heartbeats->recordSuccess(JOB_NAME, 'skipped — not a MySQL connection');
    exit(0);
}

try {
    // SHOW STATUS/VARIABLES each return a single {Variable_name, Value} row.
    $threadsConnectedRow = $conn->fetchAssociative("SHOW STATUS LIKE 'Threads_connected'");
    $maxConnectionsRow   = $conn->fetchAssociative("SHOW VARIABLES LIKE 'max_connections'");
    $slowQueriesRow      = $conn->fetchAssociative("SHOW GLOBAL STATUS LIKE 'Slow_queries'");
    $questionsRow        = $conn->fetchAssociative("SHOW GLOBAL STATUS LIKE 'Questions'");
    $dbSizeRow           = $conn->fetchAssociative(
        'SELECT SUM(data_length + index_length) AS size_bytes FROM information_schema.tables WHERE table_schema = DATABASE()',
    );
} catch (\Doctrine\DBAL\Exception $e) {
    $heartbeats->recordFailure(JOB_NAME, $e->getMessage());
    fwrite(STDERR, 'Votepit: DB health check failed (' . $e->getMessage() . ").\n");
    exit(1);
}

$connectionsUsedPercent = ($threadsConnectedRow !== false && $maxConnectionsRow !== false && (int) $maxConnectionsRow['Value'] > 0)
    ? (float) $threadsConnectedRow['Value'] / (float) $maxConnectionsRow['Value'] * 100.0
    : null;

$dbSizeMb = ($dbSizeRow !== false && $dbSizeRow['size_bytes'] !== null)
    ? (float) $dbSizeRow['size_bytes'] / 1024 / 1024
    : null;

$diskFreeBytes = disk_free_space(dirname(__DIR__));
$diskFreeMb    = $diskFreeBytes !== false ? $diskFreeBytes / 1024 / 1024 : null;

$slowQueries = $slowQueriesRow !== false ? (int) $slowQueriesRow['Value'] : null;
$questions   = $questionsRow !== false ? (int) $questionsRow['Value'] : null;

$previousDetail = $heartbeats->all()[JOB_NAME] ?? null;
/** @var array{slow_queries: int, questions: int}|null $previousCounters */
$previousCounters = null;
if ($previousDetail !== null) {
    $decoded = json_decode($previousDetail['detail'], true);
    if (is_array($decoded) && isset($decoded['slow_queries'], $decoded['questions'])) {
        $previousCounters = ['slow_queries' => (int) $decoded['slow_queries'], 'questions' => (int) $decoded['questions']];
    }
}

$slowQueryRatePercent = null;
if ($slowQueries !== null && $questions !== null && $previousCounters !== null) {
    $slowDelta     = $slowQueries - $previousCounters['slow_queries'];
    $questionDelta = $questions - $previousCounters['questions'];
    // A MySQL restart resets both counters to 0 — a negative delta means
    // that happened since the last run; skip the rate this once rather than
    // reporting a nonsensical value.
    if ($slowDelta >= 0 && $questionDelta > 0) {
        $slowQueryRatePercent = $slowDelta / $questionDelta * 100.0;
    }
}

$metrics    = new DbHealthMetrics($connectionsUsedPercent, $dbSizeMb, $diskFreeMb, $slowQueryRatePercent);
$violations = (new DbHealthChecker())->evaluate($metrics, new DbHealthThresholds());

$nextDetail = json_encode(['slow_queries' => $slowQueries ?? 0, 'questions' => $questions ?? 0]);
\assert(is_string($nextDetail));

if ($violations === []) {
    echo "All DB health metrics are within their configured thresholds.\n";
    $heartbeats->recordSuccess(JOB_NAME, $nextDetail);
    exit(0);
}

foreach ($violations as $violation) {
    echo $violation . "\n";
    $reporter->report(new \RuntimeException('DB health check: ' . $violation));
}

$heartbeats->recordFailure(JOB_NAME, implode('; ', $violations));
exit(1);
