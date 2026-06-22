<?php

declare(strict_types=1);

namespace Votepit\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Serverseitiges Fixed-Window-Rate-Limiting (security.md §6).
 *
 * Bucket-Schlüssel: "<action>:<identity>", z. B.
 *   - "magiclink:ip:1.2.3.4"
 *   - "magiclink:email:foo@bar.tld"
 *   - "submit:user:42"
 *
 * Fenster-Logik: wenn die seit window_started_at verstrichene Zeit das
 * konfigurierte Fenster übersteigt, wird der Zähler auf 1 zurückgesetzt
 * (neues Fenster), sonst inkrementiert. Limit=0 bedeutet "kein Limit".
 *
 * DB-seitig via UPSERT (rate_limits hat PRIMARY KEY auf bucket).
 */
final class RateLimiter
{
    public function __construct(private readonly Connection $conn) {}

    /**
     * @throws Exception
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0) {
            return true; // kein Limit konfiguriert → erlaubt
        }

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

        $row = $this->conn->fetchAssociative(
            'SELECT count FROM rate_limits WHERE bucket = :bucket',
            ['bucket' => $bucket]
        );

        return ((int) ($row['count'] ?? 0)) <= $limit;
    }

    /**
     * Setzt den Zähler für einen Bucket zurück (z. B. nach erfolgreicher Aktion).
     *
     * @throws Exception
     */
    public function reset(string $bucket): void
    {
        $this->conn->delete('rate_limits', ['bucket' => $bucket]);
    }
}
