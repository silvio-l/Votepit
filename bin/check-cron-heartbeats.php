#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — dead-man's-switch watchdog (deep-review-2026-09 finding e).
 *
 * bin/cleanup-expired-accounts.php, bin/cleanup-rate-limits.php,
 * bin/cleanup-old-abuse-reports.php, and
 * bin/cleanup-orphaned-notification-emails.php each record a heartbeat
 * (CronHeartbeatRepository) on every run, success or failure. None of them
 * can notice its OWN crontab entry silently going missing (removed, a
 * hosting outage, a misconfigured scheduler) — a per-run failure audit
 * event only fires while the job still runs at all. This script closes
 * that gap: it checks every known job's last heartbeat
 * against its expected interval and reports any that are overdue, via
 * ErrorReporter (Sentry when configured) plus a non-zero exit code — so
 * THIS script's own failure to run (or its process exiting non-zero) is
 * what the operator's scheduler/monitoring surfaces.
 *
 * IMPORTANT — like the jobs it watches, no cron/scheduler is part of this
 * script itself; register it as its own scheduled task (e.g. hourly is
 * plenty given the daily jobs below), separate from the jobs it monitors.
 *
 * Usage:
 *   php bin/check-cron-heartbeats.php
 */

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

$reporter = $config->sentryDsn !== ''
    ? new \Votepit\Monitoring\SentryErrorReporter($config->sentryDsn, $config->env)
    : new \Votepit\Monitoring\NullErrorReporter();

// Expected run interval per job, generously above its actual cron cadence
// (both are meant to run daily) — a wide grace window avoids false alarms
// from an hour or two of scheduler jitter.
$expectedIntervalHours = [
    'cleanup-expired-accounts'             => 26,
    'cleanup-rate-limits'                  => 26,
    'cleanup-old-abuse-reports'            => 26,
    'cleanup-orphaned-notification-emails' => 26,
];

$heartbeats = (new \Votepit\Persistence\CronHeartbeatRepository($conn))->all();
$now        = new DateTimeImmutable();

$overdue = [];
foreach ($expectedIntervalHours as $jobName => $maxHours) {
    $heartbeat = $heartbeats[$jobName] ?? null;
    if ($heartbeat === null) {
        $overdue[$jobName] = 'never ran';
        continue;
    }

    $lastRunAt = new DateTimeImmutable($heartbeat['last_run_at']);
    $ageHours  = ($now->getTimestamp() - $lastRunAt->getTimestamp()) / 3600;
    if ($ageHours > $maxHours) {
        $overdue[$jobName] = sprintf('last ran %.1fh ago (expected within %dh)', $ageHours, $maxHours);
    }
}

if ($overdue === []) {
    echo "All cron jobs are within their expected interval.\n";
    exit(0);
}

foreach ($overdue as $jobName => $reason) {
    $message = sprintf('Cron job "%s" is overdue: %s', $jobName, $reason);
    echo $message . "\n";
    $reporter->report(new RuntimeException($message));
}

exit(1);
