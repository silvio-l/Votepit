<?php

declare(strict_types=1);

namespace Votepit\Cron;

/**
 * Starts the worker process for a job whose lease the manager has just
 * claimed. The worker itself performs the run and the fenced finish; the
 * dispatcher only launches it and, in inline mode, waits for it.
 */
interface JobDispatcher
{
    /**
     * @throws \Throwable when the worker cannot be started (the manager records a failed run)
     */
    public function dispatch(CronJobDefinition $job, string $token): void;

    /** Called once per tick after all dispatches; inline mode blocks here until children exit or the deadline passes. */
    public function awaitAll(): void;
}
