<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Persistence\CronJobRepository;

/**
 * Inline mode: the tick starts each worker as a child process and polls them
 * until they exit or the tick's deadline passes. The deadline keeps the tick
 * inside its minute; anything still running then is terminated, and its lease
 * expires and is reaped as a failure on a later tick.
 */
final class InlineJobDispatcher implements JobDispatcher
{
    private const POLL_MICROSECONDS = 50_000;

    /** @var list<ChildProcess> */
    private array $children = [];

    /**
     * @param float          $deadline microtime(true) after which running children are terminated
     * @param \Closure(int): void $sleep sleeps between polls; injected so tests need not wait
     */
    public function __construct(
        private readonly CronJobRepository $jobs,
        private readonly ChildProcessSpawner $spawner,
        private readonly WorkerCommand $worker,
        private readonly string $logDir,
        private readonly float $deadline,
        private readonly \Closure $sleep,
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
        $this->children[] = $child;
    }

    public function awaitAll(): void
    {
        while (true) {
            $this->children = array_values(array_filter(
                $this->children,
                static fn (ChildProcess $child): bool => $child->isRunning(),
            ));

            if ($this->children === []) {
                return;
            }

            if (microtime(true) >= $this->deadline) {
                foreach ($this->children as $child) {
                    $child->terminate();
                }
                $this->children = [];

                return;
            }

            ($this->sleep)(self::POLL_MICROSECONDS);
        }
    }
}
