<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Votepit\Cron\CronJobDefinition;

/**
 * Lease bookkeeping for the cron manager (migrations/0090_add_cron_jobs.sql).
 *
 * Every state change is ONE statement whose WHERE clause carries the
 * precondition, and the result is the affected-row count:
 *
 *  - claim(): exactly-once per slot, and no concurrent run of the same job
 *    while a lease is alive. Two managers racing for the same slot: exactly
 *    one UPDATE matches.
 *  - finish() / expireLease(): fenced by the lease token, so a holder whose
 *    lease was reclaimed cannot write its result over the new holder's.
 *
 * Times are always UTC, formatted Y-m-d H:i:s, and compared as strings (the
 * format sorts correctly both in MySQL DATETIME and in SQLite TEXT).
 *
 * MySQL note: PDO reports *changed* rows unless MYSQL_ATTR_FOUND_ROWS is set.
 * Every precondition-guarded UPDATE here changes at least one column, so a
 * matched row is always counted; do not add a no-op UPDATE to this class.
 */
final readonly class CronJobRepository
{
    /** Added to a job's runtime budget to get its lease length, so the job's own timeout fires first. */
    public const LEASE_MARGIN_SECONDS = 120;

    public function __construct(private Connection $conn) {}

    /**
     * Creates the row, or refreshes the definition of an existing one. Lease,
     * last slot and failure state are deliberately left untouched.
     *
     * @throws DbalException
     */
    public function upsertDefinition(CronJobDefinition $job): void
    {
        $params = [
            'job_name'      => $job->name,
            'source'        => $job->source,
            'cron_expr'     => $job->cronExpr,
            'catch_up'      => $job->catchUp->value,
            'max_runtime_s' => $job->maxRuntimeSeconds,
        ];

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT INTO cron_jobs (job_name, source, cron_expr, catch_up, max_runtime_s)
                 VALUES (:job_name, :source, :cron_expr, :catch_up, :max_runtime_s)
                 ON DUPLICATE KEY UPDATE source = :source2, cron_expr = :cron_expr2, catch_up = :catch_up2, max_runtime_s = :max_runtime_s2',
                $params + [
                    'source2'        => $job->source,
                    'cron_expr2'     => $job->cronExpr,
                    'catch_up2'      => $job->catchUp->value,
                    'max_runtime_s2' => $job->maxRuntimeSeconds,
                ],
            );

            return;
        }

        // SQLite-compatible (tests). Not INSERT OR REPLACE: that would wipe the lease.
        $this->conn->executeStatement(
            'INSERT INTO cron_jobs (job_name, source, cron_expr, catch_up, max_runtime_s)
             VALUES (:job_name, :source, :cron_expr, :catch_up, :max_runtime_s)
             ON CONFLICT(job_name) DO UPDATE SET
                 source = excluded.source,
                 cron_expr = excluded.cron_expr,
                 catch_up = excluded.catch_up,
                 max_runtime_s = excluded.max_runtime_s',
            $params,
        );
    }

    /** @throws DbalException */
    public function find(string $jobName): ?CronJobState
    {
        $row = $this->conn->fetchAssociative(
            'SELECT job_name, last_slot, lease_token, lease_expires_at, pid, finished_at, last_exit,
                    consecutive_failures, alert_state, last_alerted_at
               FROM cron_jobs WHERE job_name = :job_name',
            ['job_name' => $jobName],
        );

        return $row === false ? null : CronJobState::fromRow($row);
    }

    /** @return array<string, CronJobState> keyed by job name. @throws DbalException */
    public function all(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT job_name, last_slot, lease_token, lease_expires_at, pid, finished_at, last_exit,
                    consecutive_failures, alert_state, last_alerted_at
               FROM cron_jobs',
        );

        $out = [];
        foreach ($rows as $row) {
            $state               = CronJobState::fromRow($row);
            $out[$state->jobName] = $state;
        }

        return $out;
    }

    /**
     * Claims $slot for $token. True only for the single caller whose UPDATE
     * matched: the slot is not yet claimed AND no other lease is alive.
     *
     * @throws DbalException
     */
    public function claim(string $jobName, \DateTimeImmutable $slot, string $token, \DateTimeImmutable $leaseUntil, \DateTimeImmutable $now): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE cron_jobs
                SET last_slot = :slot, lease_token = :token, lease_expires_at = :lease_until,
                    started_at = :started_at, finished_at = NULL, last_exit = NULL, pid = NULL
              WHERE job_name = :job_name
                AND (last_slot IS NULL OR last_slot < :slot_guard)
                AND (lease_token IS NULL OR lease_expires_at < :now_guard)',
            [
                'slot'        => $this->fmt($slot),
                'token'       => $token,
                'lease_until' => $this->fmt($leaseUntil),
                'started_at'  => $this->fmt($now),
                'job_name'    => $jobName,
                'slot_guard'  => $this->fmt($slot),
                'now_guard'   => $this->fmt($now),
            ],
        );

        return $affected === 1;
    }

    /**
     * Advances last_slot past a slot that is not going to run (catch-up
     * policy "none", slot too old). Does not touch a live lease.
     *
     * @throws DbalException
     */
    public function skipSlot(string $jobName, \DateTimeImmutable $slot): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE cron_jobs SET last_slot = :slot
              WHERE job_name = :job_name AND (last_slot IS NULL OR last_slot < :slot_guard)',
            ['slot' => $this->fmt($slot), 'job_name' => $jobName, 'slot_guard' => $this->fmt($slot)],
        );

        return $affected === 1;
    }

    /**
     * Takes a plain lease on a job without claiming a slot. Used to make two
     * code paths mutually exclusive (the demo's early reset and its hourly
     * reset share the 'reset-demo' lease). False if the lease is held.
     *
     * @throws DbalException
     */
    public function acquireLease(string $jobName, string $token, \DateTimeImmutable $leaseUntil, \DateTimeImmutable $now): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE cron_jobs SET lease_token = :token, lease_expires_at = :lease_until
              WHERE job_name = :job_name AND (lease_token IS NULL OR lease_expires_at < :now_guard)',
            ['token' => $token, 'lease_until' => $this->fmt($leaseUntil), 'job_name' => $jobName, 'now_guard' => $this->fmt($now)],
        );

        return $affected === 1;
    }

    /** @throws DbalException */
    public function releaseLease(string $jobName, string $token): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE cron_jobs SET lease_token = NULL, lease_expires_at = NULL
              WHERE job_name = :job_name AND lease_token = :token',
            ['job_name' => $jobName, 'token' => $token],
        );

        return $affected === 1;
    }

    /** True while $token still holds a live lease on $jobName. @throws DbalException */
    public function isLeaseHeldBy(string $jobName, string $token, \DateTimeImmutable $now): bool
    {
        return $this->conn->fetchOne(
            'SELECT 1 FROM cron_jobs
              WHERE job_name = :job_name AND lease_token = :token AND lease_expires_at >= :now_guard',
            ['job_name' => $jobName, 'token' => $token, 'now_guard' => $this->fmt($now)],
        ) !== false;
    }

    /** Records the subprocess PID of the holder (for orphan kills on timeout). @throws DbalException */
    /** Number of jobs whose lease is still alive at $now (the concurrency cap counts these). */
    public function countActiveLeases(\DateTimeImmutable $now): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM cron_jobs WHERE lease_token IS NOT NULL AND lease_expires_at >= :now_guard',
            ['now_guard' => self::fmt($now)],
        );
    }

    public function attachPid(string $jobName, string $token, int $pid): void
    {
        $this->conn->executeStatement(
            'UPDATE cron_jobs SET pid = :pid WHERE job_name = :job_name AND lease_token = :token',
            ['pid' => $pid, 'job_name' => $jobName, 'token' => $token],
        );
    }

    /**
     * Records the outcome of the holder's run and releases the lease. Fenced:
     * returns false (and writes nothing) if $token no longer holds the lease.
     * A success resets consecutive_failures; anything else increments it.
     *
     * @throws DbalException
     */
    public function finish(string $jobName, string $token, int $exitCode, \DateTimeImmutable $now): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE cron_jobs
                SET lease_token = NULL, lease_expires_at = NULL, pid = NULL,
                    finished_at = :finished_at, last_exit = :exit_code,
                    consecutive_failures = CASE WHEN :is_success = 1 THEN 0 ELSE consecutive_failures + 1 END
              WHERE job_name = :job_name AND lease_token = :token',
            [
                'finished_at' => $this->fmt($now),
                'exit_code'   => $exitCode,
                'is_success'  => $exitCode === 0 ? 1 : 0,
                'job_name'    => $jobName,
                'token'       => $token,
            ],
            // Typed binding: an untyped int reaches SQLite as TEXT, and '1' = 1 is false there.
            ['exit_code' => ParameterType::INTEGER, 'is_success' => ParameterType::INTEGER],
        );

        return $affected === 1;
    }

    /**
     * Leases whose holder ran past its expiry without finishing.
     *
     * @return list<ExpiredLease>
     * @throws DbalException
     */
    public function expiredLeases(\DateTimeImmutable $now): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT job_name, lease_token, pid FROM cron_jobs
              WHERE lease_token IS NOT NULL AND lease_expires_at < :now_guard',
            ['now_guard' => $this->fmt($now)],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = new ExpiredLease(
                (string) $row['job_name'],
                (string) $row['lease_token'],
                $row['pid'] === null ? null : (int) $row['pid'],
            );
        }

        return $out;
    }

    /**
     * Marks an expired lease as a timeout (exit -1, counted as a failure) and
     * frees it. Fenced by token and expiry, so it can run only once per lease.
     *
     * @throws DbalException
     */
    public function expireLease(string $jobName, string $token, \DateTimeImmutable $now): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE cron_jobs
                SET lease_token = NULL, lease_expires_at = NULL, pid = NULL,
                    finished_at = :finished_at, last_exit = -1,
                    consecutive_failures = consecutive_failures + 1
              WHERE job_name = :job_name AND lease_token = :token AND lease_expires_at < :now_guard',
            ['finished_at' => $this->fmt($now), 'job_name' => $jobName, 'token' => $token, 'now_guard' => $this->fmt($now)],
        );

        return $affected === 1;
    }

    /** @throws DbalException */
    public function updateAlertState(string $jobName, string $alertState, ?\DateTimeImmutable $lastAlertedAt): void
    {
        $this->conn->executeStatement(
            'UPDATE cron_jobs SET alert_state = :alert_state, last_alerted_at = :last_alerted_at WHERE job_name = :job_name',
            [
                'alert_state'     => $alertState,
                'last_alerted_at' => $lastAlertedAt instanceof \DateTimeImmutable ? $this->fmt($lastAlertedAt) : null,
                'job_name'        => $jobName,
            ],
        );
    }

    private function fmt(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
