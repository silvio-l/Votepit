<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\Cron\CronSchedule;

final class CronScheduleTest extends TestCase
{
    public function test_latest_slot_is_the_most_recent_occurrence_at_or_before_now(): void
    {
        $slot = CronSchedule::latestSlot('17 3 * * *', new \DateTimeImmutable('2026-10-22 03:17:30', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-22 03:17:00', $slot->format('Y-m-d H:i:s'));
    }

    public function test_latest_slot_before_todays_occurrence_is_yesterdays_slot(): void
    {
        $slot = CronSchedule::latestSlot('17 3 * * *', new \DateTimeImmutable('2026-10-22 03:16:59', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-21 03:17:00', $slot->format('Y-m-d H:i:s'));
    }

    public function test_latest_slot_converts_a_non_utc_clock_to_utc(): void
    {
        // 05:20 Berlin (CEST, UTC+2) is 03:20 UTC — the 03:17 UTC slot is the latest one.
        $slot = CronSchedule::latestSlot('17 3 * * *', new \DateTimeImmutable('2026-10-22 05:20:00', new \DateTimeZone('Europe/Berlin')));

        self::assertSame('2026-10-22 03:17:00', $slot->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $slot->getTimezone()->getName());
    }

    public function test_next_slot_is_strictly_after_now(): void
    {
        $next = CronSchedule::nextSlot('0 * * * *', new \DateTimeImmutable('2026-10-22 03:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-22 04:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_period_matches_how_often_the_expression_fires(): void
    {
        $now = new \DateTimeImmutable('2026-10-22 03:30:00', new \DateTimeZone('UTC'));

        self::assertSame(60, CronSchedule::periodSeconds('* * * * *', $now));
        self::assertSame(3600, CronSchedule::periodSeconds('12 * * * *', $now));
        self::assertSame(86400, CronSchedule::periodSeconds('17 3 * * *', $now));
    }
}
