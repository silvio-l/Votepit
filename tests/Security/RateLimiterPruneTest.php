<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Security review — rate_limits grows unbounded without cleanup (one bucket
 * per each once-seen IP/email/user combination and action). Covers
 * RateLimiter::pruneExpired(), which bin/cleanup-rate-limits.php calls.
 */
final class RateLimiterPruneTest extends IntegrationTestCase
{
    public function test_prune_deletes_only_buckets_whose_window_is_definitively_expired(): void
    {
        $limiter = new RateLimiter($this->conn);

        // window_seconds = 60, but window_started_at is 200s in the past
        // (> 2x safety margin) → must be deleted.
        $this->conn->insert('rate_limits', [
            'bucket'             => 'magiclink:ip:stale',
            'window_seconds'     => 60,
            'count'              => 5,
            'window_started_at'  => (new \DateTimeImmutable('-200 seconds'))->format('Y-m-d H:i:s'),
        ]);

        // window_seconds = 3600, window_started_at is only 10s in the past
        // → active window, must NOT be deleted.
        $this->conn->insert('rate_limits', [
            'bucket'             => 'magiclink:ip:active',
            'window_seconds'     => 3600,
            'count'              => 1,
            'window_started_at'  => (new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s'),
        ]);

        $deleted = $limiter->pruneExpired();

        self::assertSame(1, $deleted);
        self::assertFalse(
            (bool) $this->conn->fetchOne('SELECT 1 FROM rate_limits WHERE bucket = ?', ['magiclink:ip:stale']),
        );
        self::assertTrue(
            (bool) $this->conn->fetchOne('SELECT 1 FROM rate_limits WHERE bucket = ?', ['magiclink:ip:active']),
        );
    }
}
