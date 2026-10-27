#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — dead-man's-switch watchdog (deep-review-2026-09 finding e).
 *
 * bin/cleanup-expired-accounts.php, bin/cleanup-rate-limits.php,
 * bin/cleanup-old-abuse-reports.php,
 * bin/cleanup-orphaned-notification-emails.php, and
 * bin/purge-moderated-content.php each record a heartbeat
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
 * A job is also reported when its last run recorded a failure (status
 * "failure"), not only when its heartbeat is overdue.
 *
 * IMPORTANT — like the jobs it watches, no cron/scheduler is part of this
 * script itself; run it from its own scheduled task (e.g. hourly is plenty
 * given the daily jobs below), separate from the jobs it monitors. The cron
 * manager's own entry (bin/votepit-cron.php) is watched through 'cron-manager'.
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
    // Dispatched by the cron manager (bin/votepit-cron.php) — daily jobs,
    // wide grace window.
    'cleanup-expired-accounts'             => 26,
    'cleanup-rate-limits'                  => 26,
    'cleanup-old-abuse-reports'            => 26,
    'cleanup-orphaned-notification-emails' => 26,
    'purge-moderated-content'              => 26,
    // rotate-audit-log.php deliberately stays out of this watchlist: it is
    // a pure filesystem utility with no DB connection at all (by design,
    // see its class doc) so it has nothing to record a heartbeat against;
    // the cron manager still dispatches it, tracked only via its own
    // cron_manager_runs dispatch bookkeeping (CronJobRunner), independent
    // of a job's own heartbeat.
    'enforce-board-compliance'             => 26,
    'qualify-referrals'                    => 26,
    'expire-plan-grants'                   => 26,
    'apply-pending-plan-changes'           => 26,
    // Hourly job (CoreCronJobRegistry) — a tighter grace window than the
    // daily jobs above, matching its own dispatch cadence.
    'check-db-health'                      => 3,
    'send-weekly-digest'                   => 3,
    // The manager ticks every minute and writes its start heartbeat on each
    // tick, so 15 minutes of silence means the scheduler entry is gone or the
    // manager cannot start. Hours, so 0.25 = 15 minutes.
    'cron-manager'                         => 0.25,
];

$heartbeats = (new \Votepit\Persistence\CronHeartbeatRepository($conn))->all();
$overdue    = (new \Votepit\Cron\CronHeartbeatWatchdog($expectedIntervalHours))->overdue($heartbeats, new DateTimeImmutable());

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
