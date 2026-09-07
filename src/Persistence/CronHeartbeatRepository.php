<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Dead-man's-switch bookkeeping for cron-run jobs (deep-review-2026-09
 * finding e). One row per job, upserted on every run — see
 * migrations/0048_add_cron_heartbeats.sql and bin/check-cron-heartbeats.php.
 */
final readonly class CronHeartbeatRepository
{
    public function __construct(private Connection $conn) {}

    /** @throws DbalException */
    public function recordSuccess(string $jobName, string $detail = ''): void
    {
        $this->upsert($jobName, 'success', $detail);
    }

    /** @throws DbalException */
    public function recordFailure(string $jobName, string $detail = ''): void
    {
        $this->upsert($jobName, 'failure', $detail);
    }

    /**
     * @return array<string, array{last_run_at: string, status: string, detail: string}>
     *         keyed by job_name.
     * @throws DbalException
     */
    public function all(): array
    {
        $rows = $this->conn->fetchAllAssociative('SELECT job_name, last_run_at, status, detail FROM cron_heartbeats');

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['job_name']] = [
                'last_run_at' => (string) $row['last_run_at'],
                'status'      => (string) $row['status'],
                'detail'      => (string) $row['detail'],
            ];
        }
        return $out;
    }

    /** @throws DbalException */
    private function upsert(string $jobName, string $status, string $detail): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT INTO cron_heartbeats (job_name, last_run_at, status, detail)
                 VALUES (:job_name, :last_run_at, :status, :detail)
                 ON DUPLICATE KEY UPDATE last_run_at = :last_run_at2, status = :status2, detail = :detail2',
                [
                    'job_name'     => $jobName,
                    'last_run_at'  => $now,
                    'status'       => $status,
                    'detail'       => $detail,
                    'last_run_at2' => $now,
                    'status2'      => $status,
                    'detail2'      => $detail,
                ],
            );
            return;
        }

        // SQLite-compatible (tests).
        $this->conn->executeStatement(
            'INSERT OR REPLACE INTO cron_heartbeats (job_name, last_run_at, status, detail)
             VALUES (:job_name, :last_run_at, :status, :detail)',
            ['job_name' => $jobName, 'last_run_at' => $now, 'status' => $status, 'detail' => $detail],
        );
    }
}
