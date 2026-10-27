<?php

declare(strict_types=1);

namespace Votepit\Cron;

/**
 * The core-only cron jobs, i.e. the subset every installation has
 * (self-host as well as every Cloud tenant environment).
 *
 * Deliberately excludes bin/check-cron-heartbeats.php: that watchdog exists
 * specifically to notice if the scheduler itself has stopped calling this
 * project's cron entry points, so it stays its own, independently-scheduled
 * cron entry.
 *
 * Schedules are UTC wall-clock expressions (see CronSchedule). The daily
 * cleanups share the 03:00-05:00 UTC quiet window, each at its own minute so
 * they do not start together.
 */
final class CoreCronJobRegistry
{
    /** @return list<CronJobDefinition> */
    public static function jobs(string $coreRoot): array
    {
        $bin = $coreRoot . '/bin';

        return [
            new CronJobDefinition('cleanup-expired-accounts', [\PHP_BINARY, $bin . '/cleanup-expired-accounts.php'], '17 3 * * *', CatchUpPolicy::Latest, 1200, 'core'),
            new CronJobDefinition('cleanup-rate-limits', [\PHP_BINARY, $bin . '/cleanup-rate-limits.php'], '23 3 * * *', CatchUpPolicy::Latest, 1200, 'core'),
            new CronJobDefinition('cleanup-old-abuse-reports', [\PHP_BINARY, $bin . '/cleanup-old-abuse-reports.php'], '29 3 * * *', CatchUpPolicy::Latest, 1200, 'core'),
            new CronJobDefinition('cleanup-orphaned-notification-emails', [\PHP_BINARY, $bin . '/cleanup-orphaned-notification-emails.php'], '37 3 * * *', CatchUpPolicy::Latest, 1200, 'core'),
            new CronJobDefinition('purge-moderated-content', [\PHP_BINARY, $bin . '/purge-moderated-content.php'], '41 3 * * *', CatchUpPolicy::Latest, 1200, 'core'),
            new CronJobDefinition('rotate-audit-log', [\PHP_BINARY, $bin . '/rotate-audit-log.php'], '47 3 * * *', CatchUpPolicy::Latest, 1200, 'core'),
            // Hourly: connections/diskspace/slow-query rate can drift out of
            // bounds far faster than the daily cleanups (see bin/check-db-health.php).
            new CronJobDefinition('check-db-health', [\PHP_BINARY, $bin . '/check-db-health.php'], '5 * * * *', CatchUpPolicy::None, 300, 'core'),
            // Hourly although the digest is weekly: each run only sends a small,
            // volume-aware batch (WeeklyDigestService), so a week's digests spread
            // over many runs instead of one burst against the shared-hosting send limit.
            new CronJobDefinition('send-weekly-digest', [\PHP_BINARY, $bin . '/send-weekly-digest.php'], '12 * * * *', CatchUpPolicy::None, 900, 'core'),
            // Hourly batch for the participant digest (queued status/comment/reply
            // items) and the opt-in weekly new-ideas digest; volume-aware like the
            // weekly digest, deferring rather than dropping mail.
            new CronJobDefinition('send-participant-digest', [\PHP_BINARY, $bin . '/send-participant-digest.php'], '17 * * * *', CatchUpPolicy::None, 900, 'core'),
            // Hourly, at the lowest mail priority (after the digest): operator nudges
            // (onboarding, quiet board) back off at their own, lower volume ceilings.
            new CronJobDefinition('send-operator-nudges', [\PHP_BINARY, $bin . '/send-operator-nudges.php'], '42 * * * *', CatchUpPolicy::None, 900, 'core'),
        ];
    }
}
