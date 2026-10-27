<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\Cron\AlertDecision;
use Votepit\Cron\CronAlertPolicy;
use Votepit\Persistence\CronJobState;

final class CronAlertPolicyTest extends TestCase
{
    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function state(int $failures, string $alertState = 'ok', ?string $lastAlertedAt = null): CronJobState
    {
        return new CronJobState(
            jobName: 'job-a',
            lastSlot: null,
            leaseToken: null,
            leaseExpiresAt: null,
            pid: null,
            finishedAt: $this->at('2026-10-22 03:17:00'),
            lastExit: $failures > 0 ? 1 : 0,
            consecutiveFailures: $failures,
            alertState: $alertState,
            lastAlertedAt: $lastAlertedAt === null ? null : $this->at($lastAlertedAt),
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function thresholds(): iterable
    {
        yield 'every minute'        => ['* * * * *', 3];
        yield 'every five minutes'  => ['*/5 * * * *', 3];
        yield 'every half hour'     => ['0,30 * * * *', 3];
        yield 'hourly'              => ['0 * * * *', 1];
        yield 'daily'               => ['17 3 * * *', 1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('thresholds')]
    public function test_threshold_is_three_for_jobs_more_often_than_hourly_and_one_otherwise(string $expr, int $expected): void
    {
        self::assertSame($expected, CronAlertPolicy::failureThreshold($expr, $this->at('2026-10-22 12:00:00')));
    }

    public function test_no_alert_below_the_threshold(): void
    {
        self::assertSame(AlertDecision::None, CronAlertPolicy::decide($this->state(2), 3, $this->at('2026-10-22 12:00:00')));
    }

    public function test_alert_when_the_threshold_is_reached(): void
    {
        self::assertSame(AlertDecision::Alert, CronAlertPolicy::decide($this->state(3), 3, $this->at('2026-10-22 12:00:00')));
    }

    public function test_no_repeat_alert_within_six_hours(): void
    {
        $state = $this->state(10, 'failing', '2026-10-22 08:00:01');

        self::assertSame(AlertDecision::None, CronAlertPolicy::decide($state, 3, $this->at('2026-10-22 13:59:59')));
    }

    public function test_re_alert_once_six_hours_have_passed_while_still_failing(): void
    {
        $state = $this->state(10, 'failing', '2026-10-22 08:00:01');

        self::assertSame(AlertDecision::Realert, CronAlertPolicy::decide($state, 3, $this->at('2026-10-22 14:00:01')));
    }

    public function test_recovery_is_reported_once_when_a_failing_job_succeeds(): void
    {
        $recovered = $this->state(0, 'failing', '2026-10-22 08:00:01');

        self::assertSame(AlertDecision::Recover, CronAlertPolicy::decide($recovered, 3, $this->at('2026-10-22 09:00:00')));
    }

    public function test_a_healthy_job_that_never_failed_emits_nothing(): void
    {
        self::assertSame(AlertDecision::None, CronAlertPolicy::decide($this->state(0), 1, $this->at('2026-10-22 09:00:00')));
    }
}
