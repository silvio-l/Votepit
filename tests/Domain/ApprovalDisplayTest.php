<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\ApprovalDisplay;

/** Owner decision L-03: approval never reads 100 with down votes or 0 with up votes. */
final class ApprovalDisplayTest extends TestCase
{
    public function test_199_up_1_down_shows_99_not_100(): void
    {
        self::assertSame(99, ApprovalDisplay::percent(199 / 200 * 100, true, true));
    }

    public function test_1_up_no_down_shows_100(): void
    {
        self::assertSame(100, ApprovalDisplay::percent(100.0, true, false));
    }

    public function test_0_up_5_down_shows_0(): void
    {
        self::assertSame(0, ApprovalDisplay::percent(0.0, false, true));
    }

    public function test_1_up_300_down_shows_1_not_0(): void
    {
        self::assertSame(1, ApprovalDisplay::percent(1 / 301 * 100, true, true));
    }

    public function test_boundary_99_5_with_down_votes_stays_99(): void
    {
        self::assertSame(99, ApprovalDisplay::percent(99.5, true, true));
    }

    public function test_boundary_0_4_with_up_votes_becomes_1(): void
    {
        self::assertSame(1, ApprovalDisplay::percent(0.4, true, true));
    }

    public function test_plain_rounding_without_guard_cases(): void
    {
        self::assertSame(63, ApprovalDisplay::percent(62.5, true, true));
        self::assertSame(67, ApprovalDisplay::percent(200 / 3, true, true));
    }
}
