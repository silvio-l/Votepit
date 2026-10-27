<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Persistence\CronJobRepository;

/**
 * Runs one claimed job inside its own process (`votepit-cron.php --run-job=…`)
 * and records the fenced outcome.
 *
 * The worker first re-checks that its token still holds the lease: a worker
 * whose lease was reaped (it ran too long) must never execute the job after
 * the fact. Its finish() is fenced by the same token, so a late result cannot
 * overwrite the reaper's record.
 *
 * The job's captured stdout/stderr goes to $log (in production the worker's
 * own stdout, which the dispatcher points at logs/cron/<job>.log), so a
 * failing job leaves its error message on disk.
 */
final readonly class CronWorker
{
    public const EXIT_OK          = 0;
    public const EXIT_JOB_FAILED  = 1;
    public const EXIT_LEASE_LOST  = 2;

    public function __construct(
        private CronJobRepository $jobs,
        private ProcessRunner $processRunner,
        /** @var (\Closure(string): void)|null */
        private ?\Closure $log = null,
    ) {}

    /** @return int one of the EXIT_* constants (also the worker process's exit status) */
    public function run(CronJobDefinition $job, string $token, \DateTimeImmutable $now): int
    {
        if (!$this->jobs->isLeaseHeldBy($job->name, $token, $now)) {
            return self::EXIT_LEASE_LOST;
        }

        try {
            $result = $this->processRunner->run($job->command, $job->maxRuntimeSeconds, $job->env);
        } catch (\Throwable $e) {
            $result = new ProcessResult(-1, $e->getMessage(), false);
        }

        $exitCode = $result->timedOut ? -1 : $result->exitCode;
        if ($this->log instanceof \Closure) {
            ($this->log)(sprintf(
                "[%s] %s exit=%d%s\n%s",
                $now->format('Y-m-d H:i:s'),
                $job->name,
                $exitCode,
                $result->timedOut ? ' (timed out)' : '',
                $result->output === '' ? '' : rtrim($result->output) . "\n",
            ));
        }
        $this->jobs->finish($job->name, $token, $exitCode, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return $result->isSuccessful() ? self::EXIT_OK : self::EXIT_JOB_FAILED;
    }
}
