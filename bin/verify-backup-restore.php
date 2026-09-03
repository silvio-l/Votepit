#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — Restore-Rehearsal-CLI. Implements the migration-testing
 * methodology: real prod dump -> restore
 * into a copy -> migrations-runner dry-run (+ the app's own PHPUnit suite,
 * which already runs against its own isolated SQLite/test DB per
 * IntegrationTestCase — a separate concern, NOT re-run by this script)
 * against the restored copy -> only if green does a migration ever touch
 * real prod.
 *
 * HARD SAFETY CHECK (non-negotiable): the target DB is ALWAYS a distinct,
 * throwaway database — this script refuses to run if --target-name matches
 * the production DB name from config/config.php (Votepit\Backup\
 * RestoreRehearsal::guardTarget()). There is no flag to bypass this.
 *
 * Usage:
 *   php bin/verify-backup-restore.php --dump=/path/to/dump.sql \
 *     --target-name=votepit_restore_test [--target-host=127.0.0.1] \
 *     [--target-port=3306] [--target-user=root] [--target-pass=...]
 *
 * Cron wiring for the later real nightly job (NOT configured here —
 * needs a human with real access to the target environment):
 *   0 3 * * * ... php bin/backup-database.php --out=$DUMP && \
 *     php bin/verify-backup-restore.php --dump=$DUMP \
 *       --target-host=127.0.0.1 --target-name=votepit_restore_test
 *   Target environment: ideally one separate from the production host,
 *   in its own DB/own port, so that services already running there stay
 *   unaffected. This file itself NEVER talks to a hardcoded host — it is
 *   host-agnostic and takes target host/port/name exclusively via CLI flags.
 *
 * Style mirrors bin/migrate.php: require vendor/autoload.php, load config,
 * build dependencies, execute, clear exit codes.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}

$opts = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2];
    }
}

$dumpFile = $opts['dump'] ?? null;
if ($dumpFile === null) {
    fwrite(STDERR, "Missing: --dump=/path/to/dump.sql\n");
    exit(1);
}

$targetName = $opts['target-name'] ?? null;
if ($targetName === null) {
    fwrite(STDERR, "Missing: --target-name=<distinct-db-name> (must NOT be the production DB)\n");
    exit(1);
}

try {
    $target = \Votepit\DbConfig::fromArray([
        'host'    => $opts['target-host'] ?? $config->db->host,
        'port'    => isset($opts['target-port']) ? (int) $opts['target-port'] : $config->db->port,
        'name'    => $targetName,
        'user'    => $opts['target-user'] ?? $config->db->user,
        'pass'    => $opts['target-pass'] ?? $config->db->pass,
        'charset' => $config->db->charset,
    ]);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Target DB configuration invalid: ' . $e->getMessage() . "\n");
    exit(1);
}

$rehearsal = new \Votepit\Backup\RestoreRehearsal();

try {
    $rehearsal->guardTarget($config->db, $target);
    $rehearsal->restore($dumpFile, $target);
    $pending = $rehearsal->dryRunPendingMigrations($target, dirname(__DIR__) . '/migrations');
} catch (\Votepit\Backup\BackupException $e) {
    fwrite(STDERR, 'Restore rehearsal failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Restore + migrations dry-run succeeded against {$target->name}.\n";
echo $pending === []
    ? "No pending migrations on the restored copy.\n"
    : 'Pending on the restored copy: ' . implode(', ', $pending) . "\n";

exit(0);
