<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Persistence\CronJobState;

/**
 * Pure decision rules for the failure alerts (PRD decision 4, research §3.7).
 *
 * - Threshold: 3 consecutive failures for jobs scheduled more often than
 *   hourly, 1 otherwise. A minute job's single bad run is noise; an hourly
 *   or daily job that failed once is already news.
 * - While failing, at most one re-alert every six hours.
 * - Exactly one recovery notice, once the job succeeds again.
 */
final class CronAlertPolicy
{
    public const REALERT_INTERVAL_SECONDS = 6 * 3600;

    private const ALERT_FAILING = 'failing';
    private const ALERT_OK      = 'ok';

    public static function failureThreshold(string $cronExpr, \DateTimeImmutable $now): int
    {
        return CronSchedule::periodSeconds($cronExpr, $now) < 3600 ? 3 : 1;
    }

    public static function decide(CronJobState $state, int $threshold, \DateTimeImmutable $now): AlertDecision
    {
        if ($state->consecutiveFailures === 0) {
            return $state->alertState === self::ALERT_FAILING ? AlertDecision::Recover : AlertDecision::None;
        }

        if ($state->consecutiveFailures < $threshold) {
            return AlertDecision::None;
        }

        if ($state->alertState !== self::ALERT_FAILING) {
            return AlertDecision::Alert;
        }

        $lastAlertedAt = $state->lastAlertedAt;
        if (!$lastAlertedAt instanceof \DateTimeImmutable
            || $now->getTimestamp() - $lastAlertedAt->getTimestamp() >= self::REALERT_INTERVAL_SECONDS) {
            return AlertDecision::Realert;
        }

        return AlertDecision::None;
    }

    /** The alert_state value stored after $decision was delivered. */
    public static function stateAfter(AlertDecision $decision): string
    {
        return $decision === AlertDecision::Recover ? self::ALERT_OK : self::ALERT_FAILING;
    }
}
