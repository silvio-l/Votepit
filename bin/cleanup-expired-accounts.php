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
 * Users (competitor-parity issue 12): after the accounts, the same run
 * erases every USER whose self-deletion grace period has expired
 * (users.deletion_scheduled_at, set by POST /account/delete) via
 * Votepit\Cleanup\ExpiredUserCleaner — see UserErasureRepository for what
 * is kept (ideas/comments under a "deleted user" placeholder, anonymised
 * votes) and what is erased; configured extensions are loaded so their
 * reassignUserReferences() hook runs inside each erasure. Accounts go first, so an owner whose account
 * was purged in this run is no longer blocked as an owner.
 *
 * Usage:
 *   php bin/cleanup-expired-accounts.php            # deletes all expired accounts and users
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
    // Loaded so extension-owned user references (e.g. legal evidence an
    // extension keeps) are re-pointed to the placeholder before a user is erased
    // instead of cascading away. A broken extension aborts the run here.
    $extensions = \Votepit\Extension\ExtensionLoader::fromConfig($config);
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
$now    = new DateTimeImmutable();

// ── Accounts ─────────────────────────────────────────────────────────────
$expired = $accounts->findExpiredForDeletion($now);
$failed  = 0;
$summary = [];

if ($expired === []) {
    echo "No expired accounts.\n";
    $summary[] = 'no expired accounts';
} else {
    foreach ($expired as $account) {
        echo sprintf(
            "%s account #%d (%s), due since %s\n",
            $dryRun ? 'Would delete:' : 'Deleting:',
            $account['id'],
            $account['slug'],
            $account['deletion_scheduled_at'],
        );
    }

    if (!$dryRun) {
        // Board image files (storage/board-assets) go with the accounts they belong to.
        $boardAssetCleanup = new \Votepit\Cleanup\BoardAssetCleanup(
            new \Votepit\Persistence\BoardAssetRepository($conn),
            new \Votepit\Security\BoardAssetFileStore(dirname(__DIR__) . '/storage/board-assets'),
        );
        $cleaner = new \Votepit\Cleanup\ExpiredAccountCleaner(
            $accounts,
            $audit,
            $reporter,
            $boardAssetCleanup,
            new \Votepit\Persistence\UploadTermsConsentRepository($conn),
            new \Votepit\Security\IdentityHasher($config->identityServerKey),
        );
        $result  = $cleaner->run($expired);
        $failed += $result['failed'];
        $summary[] = sprintf('%d purged', $result['purged']);

        echo sprintf(
            "Done — %d account(s) deleted, %d failed.\n",
            $result['purged'],
            $result['failed'],
        );
    }
}

// ── Upload-clause consent proof (migration 0101) ─────────────────────────
// Pseudonymised proof of erased accounts is deleted UploadTerms::RETENTION_YEARS
// after the erasure (owner answer 29a). Live proof is never touched.
if (!$dryRun) {
    $proofPurged = (new \Votepit\Persistence\UploadTermsConsentRepository($conn))->purgePseudonymisedBefore(
        $now->modify('-' . \Votepit\Domain\UploadTerms::RETENTION_YEARS . ' years'),
    );
    echo sprintf("Upload-terms proof past retention: %d row(s) deleted.\n", $proofPurged);
}

// ── Users (self-deletion, UserSelfServiceAction) ─────────────────────────
$users        = new \Votepit\Persistence\UserRepository($conn);
$expiredUsers = $users->findExpiredForDeletion($now);

if ($expiredUsers === []) {
    echo "No expired users.\n";
} else {
    foreach ($expiredUsers as $user) {
        echo sprintf(
            "%s user #%d, due since %s\n",
            $dryRun ? 'Would erase:' : 'Erasing:',
            $user['id'],
            $user['deletion_scheduled_at'],
        );
    }

    if (!$dryRun) {
        $userCleaner = new \Votepit\Cleanup\ExpiredUserCleaner(
            $users,
            new \Votepit\Persistence\UserErasureRepository($conn, $extensions),
            new \Votepit\Domain\UserDeletionGuard(new \Votepit\Persistence\AccountMemberRepository($conn), $users),
            $audit,
            $reporter,
            dirname(__DIR__) . '/storage/avatars',
        );
        $userResult = $userCleaner->run($expiredUsers);
        $failed    += $userResult['failed'];
        $summary[]  = sprintf('%d user(s) erased, %d skipped', $userResult['purged'], $userResult['skipped']);

        echo sprintf(
            "Done — %d user(s) erased, %d skipped (blocked), %d failed.\n",
            $userResult['purged'],
            $userResult['skipped'],
            $userResult['failed'],
        );
    }
}

// ── Guest-author device hashes (ADR 0006 §10) ───────────────────────────
// After the cookie lifetime (180 days) a guest idea is no longer tied to
// a device: the hash is nulled, the idea itself stays.
$guestHashCutoff = $now->modify(sprintf('-%d days', intdiv(\Votepit\Security\AnonymousAuthorCookie::MAX_AGE, 86400)));
if ($dryRun) {
    $staleHashes = (int) $conn->fetchOne(
        'SELECT COUNT(*) FROM ideas WHERE anon_author_token_hash IS NOT NULL AND created_at < :cutoff',
        ['cutoff' => $guestHashCutoff->format('Y-m-d H:i:s')],
    );
    echo sprintf("Would clear %d guest-author device hash(es).\n", $staleHashes);
} else {
    try {
        $cleared   = (new \Votepit\Persistence\IdeaRepository($conn))->clearAnonAuthorHashesCreatedBefore($guestHashCutoff);
        $summary[] = sprintf('%d guest hash(es) cleared', $cleared);
        echo sprintf("Done — %d guest-author device hash(es) cleared.\n", $cleared);
    } catch (\Doctrine\DBAL\Exception $e) {
        $failed++;
        fwrite(STDERR, 'Votepit: guest-hash cleanup failed (' . $e->getMessage() . ").\n");
    }
}

if ($dryRun) {
    echo "Dry run complete — nothing was deleted.\n";
    exit(0);
}

if ($failed > 0) {
    $heartbeats->recordFailure('cleanup-expired-accounts', sprintf('%d failed', $failed));
} else {
    $heartbeats->recordSuccess('cleanup-expired-accounts', implode(', ', $summary));
}

exit($failed > 0 ? 1 : 0);
