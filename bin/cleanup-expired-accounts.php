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
 * migrate.php) — an automated cleanup job can't wait on STDIN; the regular
 * platform backup (CLAUDE.md: "own platform backup is mandatory") is the
 * upstream safeguard.
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

$audit    = new \Votepit\Logging\AuditLogger(dirname(__DIR__) . '/logs/audit.log');
$accounts = new \Votepit\Persistence\AccountRepository($conn);

$expired = $accounts->findExpiredForDeletion(new DateTimeImmutable());

if ($expired === []) {
    echo "No expired accounts.\n";
    exit(0);
}

$dryRun = in_array('--dry-run', $argv, true);

foreach ($expired as $account) {
    echo sprintf(
        "%s account #%d (%s), due since %s\n",
        $dryRun ? 'Would delete:' : 'Deleting:',
        $account['id'],
        $account['slug'],
        $account['deletion_scheduled_at'],
    );

    if ($dryRun) {
        continue;
    }

    // Log before the actual deletion (the account row is gone afterward —
    // see AccountRepository::purgeExpired() class doc).
    $audit->log('account.purged', [
        'account_id' => $account['id'],
        'slug'       => $account['slug'],
        'deadline'   => $account['deletion_scheduled_at'],
    ]);

    $accounts->purgeExpired((int) $account['id']);
}

echo $dryRun
    ? "Dry run complete — nothing was deleted.\n"
    : sprintf("Done — %d account(s) deleted.\n", count($expired));
