<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\ConfigException;

/**
 * How a claimed job is executed. Configured as `cron_dispatch`.
 *
 * - Inline: the tick launches each job as a child process and waits for
 *   them, bounded by the tick's time budget. Children still running at the
 *   deadline are terminated; their leases expire and are reaped as failures.
 *   This is the default: it does not depend on the host keeping children
 *   alive after the scheduled task has ended.
 * - Detached: the tick launches children and returns immediately, leaving
 *   them to run to completion. Only enable after the host test in
 *   the host survival test (documentation/operations.md, "Dispatch mode") has
 *   shown the scheduler keeps them alive.
 */
enum DispatchMode: string
{
    case Inline   = 'inline';
    case Detached = 'detached';

    /** @throws ConfigException on any value other than the two known modes (fail closed at boot). */
    public static function fromRaw(mixed $raw): self
    {
        if ($raw === null) {
            return self::Inline;
        }

        $mode = is_string($raw) ? self::tryFrom($raw) : null;
        if ($mode === null) {
            throw new ConfigException('config: "cron_dispatch" must be "inline" or "detached".');
        }

        return $mode;
    }
}
