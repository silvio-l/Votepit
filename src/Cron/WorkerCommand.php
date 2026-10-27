<?php

declare(strict_types=1);

namespace Votepit\Cron;

/** Builds the argv that re-enters the cron entry point as a single-job worker. */
final readonly class WorkerCommand
{
    public function __construct(
        private string $php,
        private string $entryScript,
    ) {}

    /** @return list<string> */
    public function argv(string $jobName, string $token): array
    {
        return [$this->php, $this->entryScript, '--run-job=' . $jobName, '--token=' . $token];
    }
}
