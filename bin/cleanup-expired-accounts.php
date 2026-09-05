#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — cleanup CLI for accounts with an expired deletion grace period.
 *
 * Finds every account whose deletion grace period has expired
 * (accounts.deletion_scheduled_at <= now — set by AccountDeleteAction on
 * an owner self-deletion, or by an extension, see
 * AccountRepository::scheduleDeletion()) and deletes it FULLY, cascading
 * (AccountRepository::purgeExpired() — see its class doc for the verified
 * FK-cascade analysis: boards, account_members, blocked_users, invites,
 * api_tokens as well as, transitively, ideas/votes/comments/
 * board_blocklist/board_smtp_settings are ALL ON DELETE CASCADE).
 *
 * IMPORTANT — no cron/scheduler is part of this script itself. It is
 * cron-callable (exit code 0 on success, non-zero on failure), but must
 * actually be registered as a scheduled task by the operator — depending
 * on the hosting environment, e.g. via `crontab` or the hoster's control panel.
 *
 * Usage:
 *   php bin/cleanup-expired-accounts.php            # deletes all expired accounts
 *   php bin/cleanup-expired-accounts.php --dry-run   # only shows what would be affected
 *
 * Style mirrors bin/migrate.php: require vendor/autoload.php, load config,
 * build dependencies, execute. No interactive backup prompt here (unlike
 * migrate.php) — an automated cleanup job can't wait on STDIN. There is no
 * platform backup to fall back on if this deletes the wrong rows (a
 * deliberate choice — no platform backup/restore tooling is built); rely
 * on `--dry-run` first and the cascading-delete precondition hooks instead.
 *
 * Per-account resilience (review 2026-09-04, item 8): the actual purge loop
 * lives in Votepit\Cleanup\ExpiredAccountCleaner (unit-tested there,
 * ExpiredAccountCleanerTest) — each account's purge runs in its own
 * try/catch, a failure is captured via the same
 * SentryErrorReporter/NullErrorReporter AppFactory already uses and logged
 * as a distinct `account.purge_failed` audit event, but does NOT abort the
 * run: every other expired account is still processed. This script's exit
 * code stays non-zero if any account failed, so a cron failure is still
 * visible at the process level too, on top of the Sentry capture.
 *
 * `account.purged` is written only AFTER purgeExpired() actually returns
 * successfully — previously it was written first, so a mid-purge failure
 * left an audit trail claiming a deletion that never completed.
 *
 * Dead-man's-switch (deep-review-2026-09 finding e): every run — including
 * a "nothing to do" no-op run — records a heartbeat via
 * CronHeartbeatRepository (migrations/0048_add_cron_heartbeats.sql), so
 * bin/check-cron-heartbeats.php can detect this job silently not running at
 * all (a broken/removed crontab entry, a hosting outage), which a per-run
 * failure audit event alone cannot.
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

$audit      = new \Votepit\Logging\AuditLogger(dirname(__DIR__) . '/logs/audit.log');
$accounts   = new \Votepit\Persistence\AccountRepository($conn);
$heartbeats = new \Votepit\Persistence\CronHeartbeatRepository($conn);
$reporter   = $config->sentryDsn !== ''
    ? new \Votepit\Monitoring\SentryErrorReporter($config->sentryDsn, $config->env)
    : new \Votepit\Monitoring\NullErrorReporter();

$dryRun = in_array('--dry-run', $argv, true);

$expired = $accounts->findExpiredForDeletion(new DateTimeImmutable());

if ($expired === []) {
    echo "No expired accounts.\n";
    if (!$dryRun) {
        $heartbeats->recordSuccess('cleanup-expired-accounts', 'no expired accounts');
    }
    exit(0);
}

foreach ($expired as $account) {
    echo sprintf(
        "%s account #%d (%s), due since %s\n",
        $dryRun ? 'Would delete:' : 'Deleting:',
        $account['id'],
        $account['slug'],
        $account['deletion_scheduled_at'],
    );
}

if ($dryRun) {
    echo "Dry run complete — nothing was deleted.\n";
    exit(0);
}

$cleaner = new \Votepit\Cleanup\ExpiredAccountCleaner($accounts, $audit, $reporter);
$result  = $cleaner->run($expired);

echo sprintf(
    "Done — %d account(s) deleted, %d failed.\n",
    $result['purged'],
    $result['failed'],
);

if ($result['failed'] > 0) {
    $heartbeats->recordFailure('cleanup-expired-accounts', sprintf('%d of %d failed', $result['failed'], count($expired)));
} else {
    $heartbeats->recordSuccess('cleanup-expired-accounts', sprintf('%d purged', $result['purged']));
}

exit($result['failed'] > 0 ? 1 : 0);
