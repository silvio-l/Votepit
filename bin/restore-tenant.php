#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — Per-Tenant-Restore-CLI (per-tenant restore capability, not just
 * full-DB restore). Given a database that already holds a FULL restored copy
 * (produced by bin/backup-database.php + bin/verify-backup-restore.php —
 * this script does not itself dump/restore anything) and one account
 * (--account-slug or --account-id), extracts JUST that account's rows
 * across every account-scoped table into a single re-importable JSON
 * document, written to --out.
 *
 * Reuses Votepit\Domain\AccountExportService (customer self-export)
 * for the actual table enumeration — see Votepit\Backup\TenantExtractor's
 * class doc for why this is deliberately NOT a third re-derivation of the
 * account-scoped table graph. TenantExtractor additionally re-verifies every
 * extracted board's account_id directly against the DB before writing the
 * document (cross-tenant-leak discipline, belt-and-suspenders on top of
 * AccountExportService's own scoping).
 *
 * This script talks to WHATEVER database --host/--name point at — by
 * default the app's own configured connection (config/config.php), which is
 * fine for extracting from a live self-host install, but for the restore-
 * rehearsal use case an operator points --host/--name at the RESTORED COPY
 * (the same throwaway DB bin/verify-backup-restore.php just restored into),
 * never at a scratch/unrelated database.
 *
 * Usage:
 *   php bin/restore-tenant.php --account-slug=acme --out=/tmp/acme-export.json
 *   php bin/restore-tenant.php --account-id=42 --out=/tmp/acct-42.json \
 *     --host=127.0.0.1 --name=votepit_restore_test
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

$outPath = $opts['out'] ?? null;
if ($outPath === null) {
    fwrite(STDERR, "Missing: --out=/path/to/export.json\n");
    exit(1);
}

$slug      = $opts['account-slug'] ?? null;
$accountId = isset($opts['account-id']) ? (int) $opts['account-id'] : null;
if ($slug === null && $accountId === null) {
    fwrite(STDERR, "Missing: --account-slug=... or --account-id=...\n");
    exit(1);
}

try {
    $db = \Votepit\DbConfig::fromArray([
        'host'    => $opts['host'] ?? $config->db->host,
        'port'    => isset($opts['port']) ? (int) $opts['port'] : $config->db->port,
        'name'    => $opts['name'] ?? $config->db->name,
        'user'    => $opts['user'] ?? $config->db->user,
        'pass'    => $opts['pass'] ?? $config->db->pass,
        'charset' => $config->db->charset,
    ]);
    $conn = \Votepit\Persistence\ConnectionFactory::createForDb($db);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Target DB configuration invalid: ' . $e->getMessage() . "\n");
    exit(1);
}

$accounts  = new \Votepit\Persistence\AccountRepository($conn);
$extractor = new \Votepit\Backup\TenantExtractor($conn, $accounts);

try {
    $document = $slug !== null ? $extractor->extractBySlug($slug) : $extractor->extractById((int) $accountId);
} catch (\Votepit\Backup\BackupException $e) {
    fwrite(STDERR, 'Extraction failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($document === null) {
    fwrite(STDERR, "Account not found: " . ($slug ?? (string) $accountId) . "\n");
    exit(1);
}

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

if (file_put_contents($outPath, $json) === false) {
    fwrite(STDERR, "Export file not writable: {$outPath}\n");
    exit(1);
}

echo "Per-tenant export written: {$outPath}\n";
exit(0);
