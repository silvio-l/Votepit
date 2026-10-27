<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Covers RateLimiter::refund() — gives back exactly one request to a bucket
 * (e.g. a successful login refunding itself from the per-IP budget) without
 * wiping the requests counted before it, unlike reset().
 */
final class RateLimiterRefundTest extends IntegrationTestCase
{
    public function test_refund_gives_back_exactly_one_request(): void
    {
        $limiter = new RateLimiter($this->conn);
        $limiter->count('test:refund', 3600);
        $limiter->count('test:refund', 3600);
        $limiter->count('test:refund', 3600);

        $limiter->refund('test:refund');

        self::assertSame(2, $limiter->peek('test:refund', 3600));
    }

    public function test_refund_never_goes_below_zero(): void
    {
        $limiter = new RateLimiter($this->conn);
        $limiter->count('test:floor', 3600);

        $limiter->refund('test:floor');
        $limiter->refund('test:floor');

        self::assertSame(0, $limiter->peek('test:floor', 3600));
        self::assertSame(1, $limiter->count('test:floor', 3600));
    }

    public function test_refund_of_an_unknown_bucket_is_a_no_op(): void
    {
        $limiter = new RateLimiter($this->conn);

        $limiter->refund('test:never-hit');

        self::assertSame(0, $limiter->peek('test:never-hit', 3600));
        self::assertSame(1, $limiter->count('test:never-hit', 3600));
    }

    public function test_refund_keeps_the_window_start(): void
    {
        $limiter = new RateLimiter($this->conn);
        $limiter->count('test:window', 60);
        $limiter->count('test:window', 60);
        $started = $this->conn->fetchOne("SELECT window_started_at FROM rate_limits WHERE bucket = 'test:window'");

        $limiter->refund('test:window');

        self::assertSame($started, $this->conn->fetchOne("SELECT window_started_at FROM rate_limits WHERE bucket = 'test:window'"));
    }
}
