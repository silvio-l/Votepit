<?php

declare(strict_types=1);

namespace Votepit\Cron;

/** Outcome of one subprocess launch (see ProcessRunner). */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public bool $timedOut,
    ) {}

    public function isSuccessful(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }
}
