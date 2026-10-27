<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use Votepit\Cron\ChildProcess;

final class FakeChildProcess implements ChildProcess
{
    public bool $terminated = false;

    private bool $running = true;

    public function __construct(private readonly int $fakePid) {}

    public function pid(): int
    {
        return $this->fakePid;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function exitCode(): ?int
    {
        return $this->running ? null : 0;
    }

    public function terminate(): void
    {
        $this->terminated = true;
        $this->running    = false;
    }

    public function finish(): void
    {
        $this->running = false;
    }
}
