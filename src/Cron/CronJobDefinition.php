<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Cron\CronExpression;

/**
 * One entry in the cron manager's registry: the argv it runs, when (a
 * 5-field cron expression evaluated in UTC, see CronSchedule), what to do
 * about missed slots (CatchUpPolicy), and how long it may run.
 *
 * Validated on construction, so a malformed definition fails at boot and
 * never reaches the scheduler.
 */
final readonly class CronJobDefinition
{
    /**
     * @param non-empty-string        $name             stable id, [a-z0-9-], at most 64 chars (cron_jobs.job_name)
     * @param non-empty-list<string>  $command          argv, e.g. ['/usr/bin/php', '/path/to/job.php']; never a shell string
     * @param non-empty-string        $cronExpr         5-field expression, UTC wall-clock
     * @param int                     $maxRuntimeSeconds runtime budget; the lease is this plus a margin
     * @param non-empty-string        $source           'core' | 'cloud' | 'demo' | ... (informational)
     * @param array<string, string>   $env              extra/overriding env vars for the subprocess, merged onto the manager's env
     */
    public function __construct(
        public string $name,
        public array $command,
        public string $cronExpr,
        public CatchUpPolicy $catchUp,
        public int $maxRuntimeSeconds,
        public string $source,
        public array $env = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException("cron job name \"{$name}\" must match [a-z0-9-], at most 64 characters.");
        }
        if (!CronExpression::isValidExpression($cronExpr)) {
            throw new \InvalidArgumentException("cron job \"{$name}\" has an invalid cron expression \"{$cronExpr}\".");
        }
        if ($maxRuntimeSeconds < 1) {
            throw new \InvalidArgumentException("cron job \"{$name}\" needs a runtime budget of at least 1 second.");
        }
    }
}
