<?php

declare(strict_types=1);

namespace Votepit\Extension;

use Votepit\Cron\CronJobDefinition;

/**
 * Optional capability of an AppExtension: contributes periodic jobs to the
 * single cron manager tick (bin/votepit-cron.php). Not part of AppExtension,
 * so extensions without jobs need not implement it.
 *
 * Job names must be unique across core and all extensions; the registry
 * (Votepit\Cron\CronJobRegistry) rejects duplicates at boot.
 */
interface CronJobProvider
{
    /**
     * @param string $coreRoot absolute path of the core installation, for the
     *                         VOTEPIT_CORE_ROOT env var the job scripts read
     * @return list<CronJobDefinition>
     */
    public function cronJobs(string $coreRoot): array;
}
