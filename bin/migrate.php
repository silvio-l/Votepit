#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — migrations CLI.
 *
 * Shows pending migrations, prompts for a backup before applying them
 * (no automatic backup — convention: migration runner with a mandatory
 * backup immediately beforehand), then applies them.
 *
 * Usage:
 *   php bin/migrate.php            # shows pending, prompts, applies
 *   php bin/migrate.php --dry-run  # only shows what would be pending
 *   php bin/migrate.php --yes      # skips the backup prompt (ONLY for
 *                                  # automated deploys on throwaway/staging
 *                                  # data, see tools/deploy.sh — NEVER
 *                                  # used for production there)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
    $conn   = \Votepit\Persistence\ConnectionFactory::create($config);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}

$runner = new \Votepit\Migrations\MigrationRunner($conn, dirname(__DIR__) . '/migrations', $config);

$pending = $runner->pending();

if ($pending === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

echo 'Pending: ' . implode(', ', array_map(static fn ($m) => $m->version(), $pending)) . "\n";

if (in_array('--dry-run', $argv, true)) {
    exit(0);
}

if (!in_array('--yes', $argv, true)) {
    echo "⚠️  Take a backup NOW. Press Enter to continue, Ctrl+C to abort.\n";
    fgets(STDIN);
}

foreach ($runner->migrate() as $version) {
    echo "Applied: {$version}\n";
}
