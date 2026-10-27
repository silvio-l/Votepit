<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Extension\ExtensionLoader;

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
}
