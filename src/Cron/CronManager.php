<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Persistence\CronJobRepository;

/**
 * One manager tick: reap expired leases, then claim and dispatch every job
 * whose latest slot has not run yet.
 *
 * - A slot runs at most once: the claim is one conditional UPDATE.
 * - A job never runs concurrently with itself: a live lease blocks the claim.
 * - At most $concurrencyCap leases are alive at once. Jobs beyond the cap are
 *   deferred to a later tick (their slot is still unclaimed).
 * - Missed slots follow the job's CatchUpPolicy.
 * - A job that cannot be started, or whose lease expired, is recorded as a
 *   failed run; it never stops the other jobs.
 * - After dispatch settles, the alerter (if any) reports failure and recovery
 *   state changes. It never throws, so it cannot fail the tick.
 *
 * The concurrency check and the claim are not one transaction, so two
 * managers racing at the same instant can briefly exceed the cap by one.
 */
final readonly class CronManager
{
    /** @var \Closure(int): bool */
    private \Closure $signalPid;

    /**
     * @param int                       $freshWindowSeconds how late a slot may be and still run under CatchUpPolicy::None
     * @param \Closure(int): bool|null  $signalPid          kills a reaped worker's pid; defaults to posix_kill(SIGKILL) when available
     */
    public function __construct(
        private CronJobRepository $jobs,
        private JobDispatcher $dispatcher,
        private int $concurrencyCap = 3,
        private int $freshWindowSeconds = 120,
        ?\Closure $signalPid = null,
        private ?CronAlerter $alerter = null,
    ) {
        $this->signalPid = $signalPid ?? static fn (int $pid): bool => function_exists('posix_kill') && posix_kill($pid, 9);
    }

    /** @param list<CronJobDefinition> $definitions */
    public function tick(array $definitions, \DateTimeImmutable $now): TickReport
    {
        $now = CronSchedule::utcMinute($now);

        $reaped = $this->reapExpiredLeases($now);

        $dispatched    = [];
        $deferred      = [];
        $failedToStart = [];

        foreach ($definitions as $job) {
            $this->jobs->upsertDefinition($job);

            $slot  = CronSchedule::latestSlot($job->cronExpr, $now);
            $state = $this->jobs->find($job->name);
            if ($state?->lastSlot instanceof \DateTimeImmutable && $state->lastSlot->getTimestamp() >= $slot->getTimestamp()) {
                continue;
            }

            $lateBy = $now->getTimestamp() - $slot->getTimestamp();
            if ($job->catchUp === CatchUpPolicy::None && $lateBy > $this->freshWindowSeconds) {
                $this->jobs->skipSlot($job->name, $slot);
                continue;
            }

            if ($this->jobs->countActiveLeases($now) >= $this->concurrencyCap) {
                $deferred[] = $job->name;
                continue;
            }

            $token      = bin2hex(random_bytes(16));
            $leaseUntil = $now->modify(sprintf('+%d seconds', $job->maxRuntimeSeconds + CronJobRepository::LEASE_MARGIN_SECONDS));
            if (!$this->jobs->claim($job->name, $slot, $token, $leaseUntil, $now)) {
                continue;
            }

            try {
                $this->dispatcher->dispatch($job, $token);
                $dispatched[] = $job->name;
            } catch (\Throwable) {
                $this->jobs->finish($job->name, $token, -1, $now);
                $failedToStart[] = $job->name;
            }
        }

        $this->dispatcher->awaitAll();
        $this->alerter?->evaluate($definitions, $now);

        return new TickReport($dispatched, $deferred, $reaped, $failedToStart);
    }

    /** @return list<string> names of jobs whose expired lease was recorded as a failure */
    private function reapExpiredLeases(\DateTimeImmutable $now): array
    {
        $reaped = [];
        foreach ($this->jobs->expiredLeases($now) as $lease) {
            // Fenced: only the first reaper to expire this exact lease gets to act on it.
            if (!$this->jobs->expireLease($lease->jobName, $lease->leaseToken, $now)) {
                continue;
            }

            $reaped[] = $lease->jobName;
            if ($lease->pid !== null) {
                ($this->signalPid)($lease->pid);
            }
        }

        return $reaped;
    }
}
