<?php

declare(strict_types=1);

namespace Votepit\Cron;

interface ChildProcess
{
    public function pid(): ?int;

    public function isRunning(): bool;

    /** Exit code once the child has stopped; null while it still runs. */
    public function exitCode(): ?int;

    /** Sends SIGTERM. */
    public function terminate(): void;
}
