<?php

declare(strict_types=1);

namespace Votepit\Cron;

/**
 * Dead-man's-switch check over the cron_heartbeats rows (bin/check-cron-heartbeats.php).
 *
 * A job is reported when its heartbeat is missing, older than its expected
 * interval, or its last run recorded a failure. The stored detail is not
 * repeated: it can contain exception text, and the alert channel is wider
 * than the database.
 */
final readonly class CronHeartbeatWatchdog
{
    /** @param array<string, float> $expectedIntervalHours per job, the maximum age of its last heartbeat */
    public function __construct(private array $expectedIntervalHours) {}

    /**
     * @param array<string, array{last_run_at: string, status: string, detail: string}> $heartbeats keyed by job name
     * @return array<string, string> job name => reason, only for jobs that need attention
     */
    public function overdue(array $heartbeats, \DateTimeImmutable $now): array
    {
        $overdue = [];
        foreach ($this->expectedIntervalHours as $jobName => $maxHours) {
            $heartbeat = $heartbeats[$jobName] ?? null;
            if ($heartbeat === null) {
                $overdue[$jobName] = 'never ran';
                continue;
            }

            $lastRunAt = new \DateTimeImmutable($heartbeat['last_run_at']);
            $ageHours  = ($now->getTimestamp() - $lastRunAt->getTimestamp()) / 3600;
            if ($ageHours > $maxHours) {
                $overdue[$jobName] = sprintf('last ran %.2fh ago (expected within %sh)', $ageHours, $maxHours);
                continue;
            }

            if ($heartbeat['status'] === 'failure') {
                $overdue[$jobName] = 'last run reported a failure';
            }
        }

        return $overdue;
    }
}
