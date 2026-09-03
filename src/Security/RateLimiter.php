<?php

declare(strict_types=1);

namespace Votepit\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Server-side fixed-window rate limiting (security.md §6).
 *
 * Bucket key: "<action>:<identity>", e.g.
 *   - "magiclink:ip:1.2.3.4"
 *   - "magiclink:email:foo@bar.tld"
 *   - "submit:user:42"
 *
 * Window logic: if the time elapsed since window_started_at exceeds the
 * configured window, the counter is reset to 1 (new window), otherwise
 * incremented. Limit=0 means "no limit".
 *
 * DB-side via UPSERT (rate_limits has PRIMARY KEY on bucket).
 */
final readonly class RateLimiter
{
    public function __construct(private Connection $conn) {}

    /**
     * @throws Exception
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0) {
            return true; // no limit configured → allowed
        }

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                "INSERT INTO rate_limits (bucket, window_seconds, count, window_started_at)
                 VALUES (:bucket, :window, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                   count = IF(TIMESTAMPDIFF(SECOND, window_started_at, NOW()) >= window_seconds,
                              1, count + 1),
                   window_started_at = IF(TIMESTAMPDIFF(SECOND, window_started_at, NOW()) >= window_seconds,
                                          NOW(), window_started_at)",
                ['bucket' => $bucket, 'window' => $windowSeconds]
            );
        } else {
            // SQLite-compatible (tests + non-MySQL deployments): two statements.
            $this->conn->executeStatement(
                "INSERT OR IGNORE INTO rate_limits (bucket, window_seconds, count, window_started_at)
                 VALUES (:bucket, :window, 0, datetime('now'))",
                ['bucket' => $bucket, 'window' => $windowSeconds]
            );
            $this->conn->executeStatement(
                "UPDATE rate_limits
                 SET count             = CASE WHEN (CAST(strftime('%s', 'now') AS INTEGER)
                                                   - CAST(strftime('%s', window_started_at) AS INTEGER))
                                                   >= window_seconds
                                              THEN 1 ELSE count + 1 END,
                     window_started_at = CASE WHEN (CAST(strftime('%s', 'now') AS INTEGER)
                                                   - CAST(strftime('%s', window_started_at) AS INTEGER))
                                                   >= window_seconds
                                              THEN datetime('now') ELSE window_started_at END
                 WHERE bucket = :bucket",
                ['bucket' => $bucket]
            );
        }

        $row = $this->conn->fetchAssociative(
            'SELECT count FROM rate_limits WHERE bucket = :bucket',
            ['bucket' => $bucket]
        );

        return ((int) ($row['count'] ?? 0)) <= $limit;
    }

    /**
     * Resets the counter for a bucket (e.g. after a successful action).
     *
     * @throws Exception
     */
    public function reset(string $bucket): void
    {
        $this->conn->delete('rate_limits', ['bucket' => $bucket]);
    }

    /**
     * Deletes buckets whose window (with a 2x safety margin against clock
     * drift/long-running requests) has definitely expired — without this
     * cleanup, rate_limits grows unbounded (one bucket per each distinct
     * IP/email/user combination ever seen; PRIMARY KEY only prevents
     * duplicates, not growth). Callable from cron via bin/cleanup-rate-limits.php.
     *
     * @throws Exception
     */
    public function pruneExpired(): int
    {
        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return (int) $this->conn->executeStatement(
                'DELETE FROM rate_limits
                 WHERE TIMESTAMPDIFF(SECOND, window_started_at, NOW()) >= window_seconds * 2',
            );
        }

        return (int) $this->conn->executeStatement(
            "DELETE FROM rate_limits
             WHERE (CAST(strftime('%s', 'now') AS INTEGER) - CAST(strftime('%s', window_started_at) AS INTEGER))
                   >= window_seconds * 2",
        );
    }
}
