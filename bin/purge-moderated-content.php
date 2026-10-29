#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — cleanup CLI for content_moderation_actions rows past their
 * retention window (board-operator-compliance issue 08).
 *
 * PRD "Further Notes" retention rule: purge_after is a fixed 30 days from
 * a moderation action's creation (ModerationRepository::recordAction()),
 * extended in effect to (appeal resolved_at + 14 days) if a linked appeal
 * is still 'pending' at the 30-day mark, so a running appeal review is
 * never overtaken by this job.
 *
 * For every content_moderation_actions row with purge_after <= now and no
 * pending appeal, this clears content_snapshot AND hard-deletes the
 * underlying soft-deleted comments/ideas row — the row itself stays (it
 * is the audit trail / repeat-offender counter, see
 * ModerationRepository's class doc), only the retained content text and
 * the moderated comment/idea row go away.
 *
 * The "pending appeal" check (Cleanup\ModerationPurgeService::hasPendingAppeal())
 * queries content_moderation_appeals via ModerationAppealRepository
 * (board-operator-compliance issue 09).
 *
 * IMPORTANT — no cron/scheduler is part of this script itself (analogous to
 * bin/cleanup-expired-accounts.php). It is cron-callable (exit code 0 on
 * success, non-zero on failure), but must be registered as a scheduled task
 * by the operator. Records a heartbeat via CronHeartbeatRepository on every
 * run (see bin/check-cron-heartbeats.php).
 *
 * Usage:
 *   php bin/purge-moderated-content.php            # purges eligible rows
 *   php bin/purge-moderated-content.php --dry-run   # only shows what's eligible
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
$moderation = new \Votepit\Persistence\ModerationRepository($conn);
$appeals    = new \Votepit\Persistence\ModerationAppealRepository($conn);
$comments   = new \Votepit\Persistence\CommentRepository($conn);
$ideas      = new \Votepit\Persistence\IdeaRepository($conn);
$heartbeats = new \Votepit\Persistence\CronHeartbeatRepository($conn);
$reporter   = $config->sentryDsn !== ''
    ? new \Votepit\Monitoring\SentryErrorReporter($config->sentryDsn, $config->env)
    : new \Votepit\Monitoring\NullErrorReporter();

$dryRun = in_array('--dry-run', $argv, true);
$now    = new DateTimeImmutable();

$eligible = $moderation->findPurgeable($now);

if ($eligible === []) {
    echo "No moderated content past its retention window.\n";
    if (!$dryRun) {
        $heartbeats->recordSuccess('purge-moderated-content', 'nothing eligible');
    }
    exit(0);
}

foreach ($eligible as $row) {
    echo sprintf(
        "%s %s #%d (moderation action #%d), due since %s\n",
        $dryRun ? 'Would purge:' : 'Purging:',
        $row['entity_type'],
        $row['entity_id'],
        $row['id'],
        $row['purge_after'],
    );
}

if ($dryRun) {
    echo "Dry run complete — nothing was purged.\n";
    exit(0);
}

$purger = new \Votepit\Cleanup\ModerationPurgeService($moderation, $appeals, $comments, $ideas, $audit, $reporter);
$result = $purger->run($now);

echo sprintf(
    "Done — %d purged, %d skipped (pending appeal), %d failed.\n",
    $result['purged'],
    $result['skipped'],
    $result['failed'],
);

if ($result['failed'] > 0) {
    $heartbeats->recordFailure('purge-moderated-content', sprintf('%d of %d failed', $result['failed'], count($eligible)));
} else {
    $heartbeats->recordSuccess('purge-moderated-content', sprintf('%d purged, %d skipped', $result['purged'], $result['skipped']));
}

exit($result['failed'] > 0 ? 1 : 0);
