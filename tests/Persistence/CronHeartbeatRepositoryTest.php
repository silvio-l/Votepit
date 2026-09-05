<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\CronHeartbeatRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Dead-man's-switch bookkeeping (deep-review-2026-09 finding e) —
 * migrations/0048_add_cron_heartbeats.sql.
 */
final class CronHeartbeatRepositoryTest extends IntegrationTestCase
{
    public function test_record_success_then_read_back(): void
    {
        $repo = new CronHeartbeatRepository($this->conn);
        $repo->recordSuccess('cleanup-expired-accounts', '2 purged');

        $all = $repo->all();
        self::assertArrayHasKey('cleanup-expired-accounts', $all);
        self::assertSame('success', $all['cleanup-expired-accounts']['status']);
        self::assertSame('2 purged', $all['cleanup-expired-accounts']['detail']);
    }

    public function test_record_failure_then_read_back(): void
    {
        $repo = new CronHeartbeatRepository($this->conn);
        $repo->recordFailure('cleanup-rate-limits', 'DB unreachable');

        $all = $repo->all();
        self::assertSame('failure', $all['cleanup-rate-limits']['status']);
        self::assertSame('DB unreachable', $all['cleanup-rate-limits']['detail']);
    }

    public function test_a_second_run_overwrites_the_first_heartbeat_for_the_same_job(): void
    {
        $repo = new CronHeartbeatRepository($this->conn);
        $repo->recordFailure('cleanup-rate-limits', 'first attempt failed');
        $repo->recordSuccess('cleanup-rate-limits', 'second attempt ok');

        $all = $repo->all();
        self::assertCount(1, $all);
        self::assertSame('success', $all['cleanup-rate-limits']['status']);
        self::assertSame('second attempt ok', $all['cleanup-rate-limits']['detail']);
    }

    public function test_unknown_job_is_absent_from_all(): void
    {
        $repo = new CronHeartbeatRepository($this->conn);
        self::assertSame([], $repo->all());
    }
}
