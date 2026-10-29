<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Covers RateLimiter::count() (review-2026-09-04-fixes item 15) — same
 * fixed-window increment as hit(), but returning the raw count instead of
 * an allow/deny decision. Used by MailVolumeMonitor to alert on absolute
 * outbound-mail volume rather than to block anything.
 */
final class RateLimiterCountTest extends IntegrationTestCase
{
    public function test_count_increments_within_the_same_window(): void
    {
        $limiter = new RateLimiter($this->conn);

        self::assertSame(1, $limiter->count('test:bucket', 3600));
        self::assertSame(2, $limiter->count('test:bucket', 3600));
        self::assertSame(3, $limiter->count('test:bucket', 3600));
    }

    public function test_count_resets_once_the_window_has_elapsed(): void
    {
        $limiter = new RateLimiter($this->conn);
        self::assertSame(1, $limiter->count('test:expiring', 60));

        $this->conn->update(
            'rate_limits',
            ['window_started_at' => (new \DateTimeImmutable('-120 seconds'))->format('Y-m-d H:i:s')],
            ['bucket' => 'test:expiring'],
        );

        self::assertSame(1, $limiter->count('test:expiring', 60));
    }

    public function test_count_and_hit_share_the_same_underlying_counter(): void
    {
        $limiter = new RateLimiter($this->conn);

        self::assertTrue($limiter->hit('test:shared', 5, 3600));
        self::assertTrue($limiter->hit('test:shared', 5, 3600));
        self::assertSame(3, $limiter->count('test:shared', 3600));
    }
}
