<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\Cron\CronHeartbeatWatchdog;

final class CronHeartbeatWatchdogTest extends TestCase
{
    private const NOW = '2026-10-22 12:00:00';

    /** @return array{last_run_at: string, status: string, detail: string} */
    private function heartbeat(string $lastRunAt, string $status = 'success', string $detail = ''): array
    {
        return ['last_run_at' => $lastRunAt, 'status' => $status, 'detail' => $detail];
    }

    private function watchdog(): CronHeartbeatWatchdog
    {
        return new CronHeartbeatWatchdog(['cron-manager' => 0.25, 'check-db-health' => 3, 'cleanup-rate-limits' => 26]);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    public function test_fresh_successful_heartbeats_are_not_overdue(): void
    {
        $heartbeats = [
            'cron-manager'       => $this->heartbeat('2026-10-22 11:59:30'),
            'check-db-health'    => $this->heartbeat('2026-10-22 11:00:00'),
            'cleanup-rate-limits' => $this->heartbeat('2026-10-21 12:00:00'),
        ];

        self::assertSame([], $this->watchdog()->overdue($heartbeats, $this->now()));
    }

    public function test_a_manager_silent_for_fifteen_minutes_is_overdue(): void
    {
        $heartbeats = ['cron-manager' => $this->heartbeat('2026-10-22 11:44:00')];

        $overdue = $this->watchdog()->overdue($heartbeats, $this->now());

        self::assertArrayHasKey('cron-manager', $overdue);
        self::assertStringContainsString('expected within 0.25h', $overdue['cron-manager']);
    }

    public function test_a_job_whose_last_run_failed_is_flagged_even_when_fresh(): void
    {
        $heartbeats = ['check-db-health' => $this->heartbeat('2026-10-22 11:30:00', 'failure', 'SQLSTATE[HY000] secret detail')];

        $overdue = $this->watchdog()->overdue($heartbeats, $this->now());

        self::assertArrayHasKey('check-db-health', $overdue);
        self::assertStringContainsString('last run reported a failure', $overdue['check-db-health']);
        self::assertStringNotContainsString('SQLSTATE', $overdue['check-db-health'], 'the stored detail is not repeated in alerts');
    }

    public function test_a_failed_manager_tick_is_flagged_as_failure(): void
    {
        $heartbeats = ['cron-manager' => $this->heartbeat('2026-10-22 11:59:50', 'failure', 'registry broken')];

        self::assertArrayHasKey('cron-manager', $this->watchdog()->overdue($heartbeats, $this->now()));
    }

    public function test_a_job_without_any_heartbeat_is_reported_as_never_ran(): void
    {
        $overdue = $this->watchdog()->overdue([], $this->now());

        self::assertSame('never ran', $overdue['check-db-health']);
    }
}
