<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Covers RateLimiter::peek() (OWASP/BSI audit remediation issue 20:
 * anti-automation challenge) — read-only, unlike hit()/count(): never
 * increments or creates a row. Used by TurnstileGuard::requiresChallenge()
 * to read the suspicion counter without inflating it.
 */
final class RateLimiterPeekTest extends IntegrationTestCase
{
    public function test_peek_returns_zero_for_an_unknown_bucket(): void
    {
        $limiter = new RateLimiter($this->conn);

        self::assertSame(0, $limiter->peek('test:never-hit', 3600));
    }

    public function test_peek_never_increments_the_counter(): void
    {
        $limiter = new RateLimiter($this->conn);
        $limiter->count('test:peeked', 3600);

        self::assertSame(1, $limiter->peek('test:peeked', 3600));
        self::assertSame(1, $limiter->peek('test:peeked', 3600));
        self::assertSame(1, $limiter->peek('test:peeked', 3600));
    }

    public function test_peek_reflects_hits_made_via_count(): void
    {
        $limiter = new RateLimiter($this->conn);
        $limiter->count('test:peek-count', 3600);
        $limiter->count('test:peek-count', 3600);
        $limiter->count('test:peek-count', 3600);

        self::assertSame(3, $limiter->peek('test:peek-count', 3600));
    }

    public function test_peek_returns_zero_once_the_window_has_expired(): void
    {
        $limiter = new RateLimiter($this->conn);
        $limiter->count('test:peek-expiring', 60);

        $this->conn->update(
            'rate_limits',
            ['window_started_at' => (new \DateTimeImmutable('-120 seconds'))->format('Y-m-d H:i:s')],
            ['bucket' => 'test:peek-expiring'],
        );

        self::assertSame(0, $limiter->peek('test:peek-expiring', 60));
    }
}
