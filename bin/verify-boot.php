#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — deploy-time boot smoke check.
 *
 * Instantiates the configured AppExtensions the same way AppFactory::create()
 * does (ExtensionLoader::fromConfig()), without needing an authenticated HTTP
 * request to reach that code path. Exists because an HTTP-only health check
 * can be fooled: staging's Basic-Auth gate rejects every unauthenticated
 * request with 401 BEFORE PHP even runs, so `curl` never actually exercises
 * application code there. This is what let a fatal
 * "class does not implement all abstract methods" error (a stale
 * extensions/roadmap-gantt install left behind after an AppExtension
 * interface change) ship to staging
 * undetected on 2026-09-09: every unauthenticated bootstrap check "passed"
 * with 401 while every real request 500'd.
 *
 * Usage: php bin/verify-boot.php [path/to/config.php]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = $argv[1] ?? dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
    \Votepit\Extension\ExtensionLoader::fromConfig($config);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Votepit: boot check failed (' . $e::class . ': ' . $e->getMessage() . ").\n");
    exit(1);
}

echo "OK: config + extensions boot cleanly.\n";
