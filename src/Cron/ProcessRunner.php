<?php

declare(strict_types=1);

namespace Votepit\Cron;

/**
 * Launches one cron job's command as its own OS subprocess (never in-process
 * `include`/`require`) — this is what keeps one job's fatal error, uncaught
 * exception, or infinite loop from ever affecting the manager itself or any
 * other job in the same tick.
 */
interface ProcessRunner
{
    /**
     * @param non-empty-list<string> $command argv, executed directly (no shell), so no
     *                                         argument ever needs shell-escaping and no
     *                                         command string is ever built by concatenation.
     * @param array<string, string>  $env     extra/overriding env vars, merged onto the
     *                                         caller's own environment.
     */
    public function run(array $command, int $timeoutSeconds, array $env = []): ProcessResult;
}
