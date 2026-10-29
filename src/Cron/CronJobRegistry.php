<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Extension\ExtensionLoader;
use Votepit\Update\CommunityEdition;

/**
 * The complete job list of one installation: core's own jobs plus whatever
 * the configured extensions contribute through CronJobProvider. Both edition
 * entry points (bin/votepit-cron.php, cloud/bin/run-due-crons.php) build the
 * list here, so there is exactly one place that decides what runs.
 */
final class CronJobRegistry
{
    /** @return list<CronJobDefinition> */
    public static function forConfig(Config $config, string $coreRoot): array
    {
        $jobs = [
            ...CoreCronJobRegistry::jobs($coreRoot),
            ...self::selfHostJobs($config, $coreRoot),
            ...ExtensionLoader::cronJobs(ExtensionLoader::fromConfig($config), $coreRoot),
        ];

        $seen = [];
        foreach ($jobs as $job) {
            if (isset($seen[$job->name])) {
                throw new ConfigException("config: cron job \"{$job->name}\" is declared more than once.");
            }
            $seen[$job->name] = true;
        }

        return $jobs;
    }

    /**
     * Community-only jobs: the daily update check and version report. Never on
     * routing_mode 'cloud' and never on the demo, see
     * CommunityEdition::runsUpdateChannel() and bin/community-update-check.php. Both switches are checked inside the job.
     *
     * @return list<CronJobDefinition>
     */
    private static function selfHostJobs(Config $config, string $coreRoot): array
    {
        if (!CommunityEdition::runsUpdateChannel($config)) {
            return [];
        }

        return [
            new CronJobDefinition(
                'community-update-check',
                [\PHP_BINARY, $coreRoot . '/bin/community-update-check.php'],
                '31 6 * * *',
                CatchUpPolicy::None,
                300,
                'core',
            ),
        ];
    }
}
