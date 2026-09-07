#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — cleanup CLI for resolved abuse reports past their retention
 * window (deep-review-2026-09 finding g: abuse_reports.reporter_email_enc
 * is personal data — encrypted at rest, but still PII — kept indefinitely
 * with no prior retention limit, a DSGVO Art. 5(1)(e) storage-limitation gap).
 *
 * Deletes, via AbuseReportRepository::purgeReviewedBefore(), every report
 * whose status is 'reviewed'/'dismissed' and whose reviewed_at is older
 * than RETENTION_DAYS. A still-'open' (unhandled) report is NEVER touched,
 * however old — the operator must resolve it first.
 *
 * ⚠️ NOT LEGAL ADVICE. 180 days after resolution is a placeholder default
 * balancing DSA moderation-dispute/appeal windows against storage
 * limitation; confirm the actual figure against your own legal/compliance
 * guidance before relying on it in production.
 *
 * IMPORTANT — no cron/scheduler is part of this script itself (analogous to
 * bin/cleanup-expired-accounts.php). It is cron-callable (exit code 0 on
 * success, non-zero on failure), but must be registered as a scheduled task
 * by the operator. Records a heartbeat via CronHeartbeatRepository on every
 * run (see bin/check-cron-heartbeats.php).
 *
 * Usage:
 *   php bin/cleanup-old-abuse-reports.php            # deletes eligible reports
 *   php bin/cleanup-old-abuse-reports.php --dry-run   # only shows the count
 */

const RETENTION_DAYS = 180;

require dirname(__DIR__) . '/vendor/autoload.php';

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

$heartbeats = new \Votepit\Persistence\CronHeartbeatRepository($conn);
$reports    = new \Votepit\Persistence\AbuseReportRepository($conn);
$cutoff     = new DateTimeImmutable(sprintf('-%d days', RETENTION_DAYS));
$dryRun     = in_array('--dry-run', $argv, true);

if ($dryRun) {
    $count = (int) $conn->fetchOne(
        "SELECT COUNT(*) FROM abuse_reports WHERE status IN ('reviewed', 'dismissed') AND reviewed_at < :cutoff",
        ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
    );
    echo sprintf("Would delete %d resolved report(s) older than %d days.\n", $count, RETENTION_DAYS);
    echo "Dry run complete — nothing was deleted.\n";
    exit(0);
}

try {
    $deleted = $reports->purgeReviewedBefore($cutoff);
} catch (\Doctrine\DBAL\Exception $e) {
    $heartbeats->recordFailure('cleanup-old-abuse-reports', $e->getMessage());
    fwrite(STDERR, 'Votepit: abuse-report cleanup failed (' . $e->getMessage() . ").\n");
    exit(1);
}

echo sprintf("Done — %d resolved abuse report(s) older than %d days deleted.\n", $deleted, RETENTION_DAYS);

$heartbeats->recordSuccess('cleanup-old-abuse-reports', sprintf('%d deleted', $deleted));
