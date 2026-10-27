<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

use Doctrine\DBAL\Connection;
use Votepit\Migrations\MigrationRunner;
use Votepit\Persistence\CronHeartbeatRepository;

/**
 * Readiness evaluation behind GET /ready (as opposed to GET /health, which
 * is liveness only and never touches the DB). An installation is ready when
 *   - the database answers `SELECT 1`,
 *   - no migration stream (core + every extension stream) has a pending
 *     migration, and
 *   - the cron manager's start heartbeat ('cron-manager', written at the
 *     start of every tick of bin/votepit-cron.php) is younger than the
 *     configured maximum. A failed tick still counts as a tick: job and
 *     manager failures are alerted separately (CronAlerter, watchdog).
 *
 * Fail-secure: any exception counts as a failed check. Read-only: nothing
 * here writes to the database, so a probe hammering the endpoint cannot
 * change state. Returns only check names — callers must not put exception
 * messages anywhere public.
 */
final readonly class ReadinessCheck
{
    public const DATABASE   = 'database';
    public const MIGRATIONS = 'migrations';
    public const CRON       = 'cron';

    private const CRON_MANAGER_JOB = 'cron-manager';

    /** @param list<MigrationRunner> $migrationRunners */
    public function __construct(
        private Connection $conn,
        private array $migrationRunners,
        private int $cronMaxAgeMinutes,
    ) {}

    /** @return list<string> names of the failed checks; [] means ready */
    public function failures(\DateTimeImmutable $now): array
    {
        try {
            $this->conn->fetchOne('SELECT 1');
        } catch (\Throwable) {
            // Every later check would only repeat the same connection error.
            return [self::DATABASE];
        }

        $failures = [];
        if (!$this->migrationsUpToDate()) {
            $failures[] = self::MIGRATIONS;
        }
        if (!$this->cronFresh($now)) {
            $failures[] = self::CRON;
        }

        return $failures;
    }

    private function migrationsUpToDate(): bool
    {
        try {
            foreach ($this->migrationRunners as $runner) {
                if (!$runner->isUpToDate()) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function cronFresh(\DateTimeImmutable $now): bool
    {
        try {
            $heartbeat = (new CronHeartbeatRepository($this->conn))->all()[self::CRON_MANAGER_JOB] ?? null;
            if ($heartbeat === null) {
                return false;
            }

            $lastRunAt = new \DateTimeImmutable($heartbeat['last_run_at']);
        } catch (\Throwable) {
            return false;
        }

        return $lastRunAt->modify("+{$this->cronMaxAgeMinutes} minutes") >= $now;
    }
}
