<?php

declare(strict_types=1);

namespace Votepit\Cron;

/** What one manager tick did, by job name. */
final readonly class TickReport
{
    /**
     * @param list<string> $dispatched    claimed and handed to the dispatcher
     * @param list<string> $deferred      due, but the concurrency cap was full (retried next tick)
     * @param list<string> $reaped        had an expired lease, recorded as a failed run
     * @param list<string> $failedToStart claimed, but the worker could not be started (recorded as a failed run)
     */
    public function __construct(
        public array $dispatched,
        public array $deferred,
        public array $reaped,
        public array $failedToStart,
    ) {}
}
