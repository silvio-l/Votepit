#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — cron manager, the single scheduled task an installation needs:
 *
 *   * * * * * php <root>/bin/votepit-cron.php
 *
 * Every minute it reaps expired leases and dispatches the jobs whose latest
 * slot has not run yet (see Votepit\Cron\CronManager). Each job runs in its own
 * worker process (`--run-job`), so one job's crash or hang never affects the
 * others. Dispatch mode comes from config `cron_dispatch` ("inline" by default).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$coreRoot   = dirname(__DIR__);
$configPath = $coreRoot . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

// Update maintenance window (storage/maintenance.flag, see bin/update.php): no
// job starts while the code is being swapped and migrated.
if (is_file($coreRoot . '/storage/maintenance.flag')) {
    exit(0);
}

try {
    $rawConfig = require $configPath;
    $config    = \Votepit\Config::fromArray($rawConfig);
    $conn      = \Votepit\Persistence\ConnectionFactory::create($config);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}

exit(\Votepit\Cron\CronCli::run(
    $argv,
    $coreRoot,
    __FILE__,
    $config,
    $rawConfig,
    $conn,
    static fn (): array => \Votepit\Cron\CronJobRegistry::forConfig($config, $coreRoot),
));
