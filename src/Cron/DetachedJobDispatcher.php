<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Persistence\CronJobRepository;

/**
 * Detached mode: the tick starts each worker and returns at once; the worker
 * runs on after the tick ends. Whether the host keeps such children alive
 * after the scheduled task exits is what the host survival test in
 * documentation/operations.md ("Dispatch mode") decides.
 */
final readonly class DetachedJobDispatcher implements JobDispatcher
{
    public function __construct(
        private CronJobRepository $jobs,
        private ChildProcessSpawner $spawner,
        private WorkerCommand $worker,
        private string $logDir,
    ) {}

    public function dispatch(CronJobDefinition $job, string $token): void
    {
        $child = $this->spawner->spawn(
            $this->worker->argv($job->name, $token),
            $this->logDir . '/' . $job->name . '.log',
        );

        if ($child->pid() !== null) {
            $this->jobs->attachPid($job->name, $token, $child->pid());
        }
    }

    public function awaitAll(): void {}
}
