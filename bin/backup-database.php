#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — Backup-CLI. Own-platform backup (Art. 32 GDPR) — the FIRST leg
 * of the three-layer model (webhost / own / customer): produces a portable,
 * InnoDB/FK-safe SQL dump of the configured MySQL database by shelling out
 * to `mysqldump` (see Votepit\Backup\DatabaseBackup's class doc for why
 * mysqldump-shell-out was chosen over a pure-PHP dumper).
 *
 * This script does NOT decide where the dump ends up long-term — it only
 * writes the file to $outputDir/backups (or an explicit --out=... path).
 * Moving that file to off-site storage is a SEPARATE, operator-specific
 * step (rsync/scp run by a human or a follow-up cron entry).
 *
 * Usage:
 *   php bin/backup-database.php                  # dump to backups/votepit-<db>-<ts>.sql
 *   php bin/backup-database.php --out=/path/to/file.sql
 *
 * Cron example for the later real nightly job (NOT configured here —
 * a human must register this on the real host):
 *   0 3 * * * cd /path/to/core && php bin/backup-database.php --out=/path/to/backups/votepit-$(date +\%Y\%m\%d).sql \
 *     && php bin/verify-backup-restore.php --dump=/path/to/backups/votepit-$(date +\%Y\%m\%d).sql \
 *          --target-name=votepit_restore_test --target-host=127.0.0.1
 *   (Target host for the second step: ideally a DB/port environment separate
 *   and isolated from the production host.)
 *
 * Style mirrors bin/migrate.php / bin/cleanup-expired-accounts.php: require
 * vendor/autoload.php, load config, build dependencies, execute, clear
 * exit codes.
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

$requestedOut = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $requestedOut = substr($arg, strlen('--out='));
    }
}

$backup = new \Votepit\Backup\DatabaseBackup($config->db);

try {
    $outputPath = $backup->resolveOutputPath($requestedOut, dirname(__DIR__) . '/backups');
    $backup->run($outputPath);
} catch (\Votepit\Backup\BackupException $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Backup written: {$outputPath}\n";
exit(0);
