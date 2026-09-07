#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — cleanup CLI for orphaned users.notification_email addresses
 * (deep-review-2026-09 finding j).
 *
 * notification_email is a global column on `users`, not scoped to any
 * account (migrations/0029_add_notification_email_preferences.sql) — it
 * previously had no cleanup path when a user lost membership in every
 * account they belonged to (removed as a member everywhere, without
 * deleting the account itself). With no account left to be notified
 * about, keeping the address around serves no purpose (DSGVO Art. 5(1)(e)
 * storage limitation).
 *
 * Clears notification_email (+ its *_email flags) via
 * UserRepository::clearNotificationEmail() for every user
 * UserRepository::findOrphanedNotificationEmailUserIds() finds — this
 * deliberately excludes operator/support agents, who rely on
 * notification_email with zero account memberships by design.
 *
 * IMPORTANT — no cron/scheduler is part of this script itself (analogous to
 * bin/cleanup-expired-accounts.php). It is cron-callable (exit code 0 on
 * success, non-zero on failure), but must be registered as a scheduled task
 * by the operator. Records a heartbeat via CronHeartbeatRepository on every
 * run (see bin/check-cron-heartbeats.php).
 *
 * Usage:
 *   php bin/cleanup-orphaned-notification-emails.php            # clears them
 *   php bin/cleanup-orphaned-notification-emails.php --dry-run  # only shows the count
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

$heartbeats = new \Votepit\Persistence\CronHeartbeatRepository($conn);
$users      = new \Votepit\Persistence\UserRepository($conn);
$dryRun     = in_array('--dry-run', $argv, true);

try {
    $orphanedIds = $users->findOrphanedNotificationEmailUserIds();
} catch (\Doctrine\DBAL\Exception $e) {
    $heartbeats->recordFailure('cleanup-orphaned-notification-emails', $e->getMessage());
    fwrite(STDERR, 'Votepit: orphaned notification-email lookup failed (' . $e->getMessage() . ").\n");
    exit(1);
}

if ($dryRun) {
    echo sprintf("Would clear %d orphaned notification_email address(es).\n", count($orphanedIds));
    echo "Dry run complete — nothing was changed.\n";
    exit(0);
}

foreach ($orphanedIds as $userId) {
    $users->clearNotificationEmail($userId);
}

echo sprintf("Done — %d orphaned notification_email address(es) cleared.\n", count($orphanedIds));

$heartbeats->recordSuccess('cleanup-orphaned-notification-emails', sprintf('%d cleared', count($orphanedIds)));
