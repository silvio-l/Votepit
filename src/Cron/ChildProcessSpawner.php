<?php

declare(strict_types=1);

namespace Votepit\Cron;

interface ChildProcessSpawner
{
    /**
     * Starts argv as an independent process (no shell, no pipes); stdout and
     * stderr are appended to $logPath.
     *
     * @param list<string> $argv
     * @throws \RuntimeException when the process cannot be started
     */
    public function spawn(array $argv, string $logPath): ChildProcess;
}
