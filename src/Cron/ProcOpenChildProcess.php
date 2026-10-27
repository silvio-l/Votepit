<?php

declare(strict_types=1);

namespace Votepit\Cron;

final class ProcOpenChildProcess implements ChildProcess
{
    private ?int $pid = null;

    private ?int $exitCode = null;

    private bool $stopped = false;

    /** @param resource $process */
    public function __construct(private $process)
    {
        $this->pid = proc_get_status($this->process)['pid'];
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        if ($this->stopped) {
            return false;
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            return true;
        }

        // proc_get_status() reports the exit code only on the call that
        // observes the exit, so it is captured here and cached.
        $this->stopped  = true;
        $this->exitCode = $status['exitcode'];
        proc_close($this->process);

        return false;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function terminate(): void
    {
        if (!$this->stopped) {
            proc_terminate($this->process, 15);
        }
    }
}
