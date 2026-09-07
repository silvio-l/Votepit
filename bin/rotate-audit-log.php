#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — rotation CLI for logs/audit.log (security.md §8, A09 — observability
 * hardening). AuditLogger::log() appends one line per security event forever
 * (file_put_contents(..., FILE_APPEND)) with no size cap of its own — on a
 * busy installation that grows unbounded and can eventually fill the disk
 * (a self-inflicted availability incident, exactly what CLAUDE.md's logging
 * requirement explicitly warns against: "never let storage fill up").
 * Operators who document log rotation in their GDPR records of processing
 * need this script to make that claim true.
 *
 * Classic logrotate-style rotation, done in plain PHP (no logrotate binary
 * assumed to be available/configurable on shared hosting): once audit.log
 * exceeds SIZE_THRESHOLD_BYTES, it is gzip-compressed to audit.log.1.gz and
 * a fresh empty audit.log is started; existing audit.log.N.gz files shift up
 * by one (N -> N+1); anything beyond KEEP_GENERATIONS is deleted. Below the
 * threshold: no-op (most runs, if invoked frequently by cron, do nothing).
 *
 * Audit-log content is already PII-masked at write time (AuditLogger::mask())
 * — rotation/deletion here is a retention control, not a second masking pass.
 *
 * IMPORTANT — no cron/scheduler is part of this script itself (analogous to
 * bin/cleanup-expired-accounts.php / bin/cleanup-rate-limits.php). It is
 * cron-callable (exit code 0 on success, non-zero on failure), but must
 * be registered as a scheduled task by the operator.
 *
 * Usage:
 *   php bin/rotate-audit-log.php             # rotates if above the threshold
 *   php bin/rotate-audit-log.php --dry-run   # only shows what would happen
 */

const SIZE_THRESHOLD_BYTES = 20 * 1024 * 1024; // 20 MB
const KEEP_GENERATIONS     = 12; // ~1 year with monthly rotation, more with more frequent rotation

$dryRun   = in_array('--dry-run', $argv, true);
$logPath  = dirname(__DIR__) . '/logs/audit.log';

if (!is_file($logPath)) {
    echo "No audit.log present — nothing to do.\n";
    exit(0);
}

$size = filesize($logPath);
if ($size === false) {
    fwrite(STDERR, "Could not determine file size of {$logPath}.\n");
    exit(1);
}

if ($size < SIZE_THRESHOLD_BYTES) {
    echo sprintf(
        "audit.log is %.1f MB (< %.0f MB threshold) — no rotation needed.\n",
        $size / 1024 / 1024,
        SIZE_THRESHOLD_BYTES / 1024 / 1024,
    );
    exit(0);
}

echo sprintf("audit.log is %.1f MB — rotating.\n", $size / 1024 / 1024);

if ($dryRun) {
    echo "--dry-run: would shift audit.log.1.gz .. audit.log." . KEEP_GENERATIONS . ".gz, compress audit.log, start a new empty audit.log.\n";
    exit(0);
}

// Delete the oldest generation first, in case the shift would push it
// past the retention limit.
$oldestPath = $logPath . '.' . KEEP_GENERATIONS . '.gz';
if (is_file($oldestPath) && !@unlink($oldestPath)) {
    fwrite(STDERR, "Could not delete oldest generation: {$oldestPath}\n");
    exit(1);
}

// Shift generations up: audit.log.(N-1).gz -> audit.log.N.gz, descending
// (so no target is overwritten before it has itself been moved).
for ($gen = KEEP_GENERATIONS - 1; $gen >= 1; $gen--) {
    $from = $logPath . '.' . $gen . '.gz';
    $to   = $logPath . '.' . ($gen + 1) . '.gz';
    if (is_file($from) && !@rename($from, $to)) {
        fwrite(STDERR, "Could not move generation: {$from} -> {$to}\n");
        exit(1);
    }
}

// Gzip-compress the current audit.log to audit.log.1.gz.
$raw = file_get_contents($logPath);
if ($raw === false) {
    fwrite(STDERR, "Could not read {$logPath}.\n");
    exit(1);
}

$compressed = gzencode($raw, 9);
if ($compressed === false) {
    fwrite(STDERR, "gzencode() failed.\n");
    exit(1);
}

$rotatedPath = $logPath . '.1.gz';
if (file_put_contents($rotatedPath, $compressed, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write {$rotatedPath}.\n");
    exit(1);
}

// Fail-secure ordering: the compressed copy is safely written FIRST,
// audit.log is only THEN emptied — a crash in between loses no line in
// the worst case (the original file stays intact until the last step).
if (file_put_contents($logPath, '', LOCK_EX) === false) {
    fwrite(STDERR, "Could not clear {$logPath} (rotation was still saved to {$rotatedPath}).\n");
    exit(1);
}

echo sprintf("Done — audit.log rotated to %s, new empty audit.log started.\n", $rotatedPath);
