<?php

declare(strict_types=1);

namespace Votepit\Logging;

/**
 * Verifies an AuditLogger NDJSON file's hash chain (OWASP/BSI audit
 * remediation issue 11) — detects deletion, reordering, or in-place editing
 * of any historical line.
 */
final class AuditLogVerifier
{
    private function __construct() {}

    /**
     * Walks the chain from the first line; returns the 1-based line number
     * of the first break (bad JSON, wrong `prev`, or wrong `hash`), or null
     * if every line checks out (including an empty or missing file).
     */
    public static function firstBrokenLine(string $logPath): ?int
    {
        if (!is_file($logPath)) {
            return null;
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        $expectedPrev = AuditLogger::genesisHash();

        foreach ($lines as $i => $line) {
            $entry = json_decode($line, true);
            if (
                !is_array($entry)
                || !is_string($entry['prev'] ?? null)
                || !is_string($entry['hash'] ?? null)
            ) {
                return $i + 1;
            }

            if ($entry['prev'] !== $expectedPrev) {
                return $i + 1;
            }

            $withoutHash = $entry;
            unset($withoutHash['hash']);
            $recomputed = hash('sha256', (string) json_encode($withoutHash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if (!hash_equals($recomputed, $entry['hash'])) {
                return $i + 1;
            }

            $expectedPrev = $entry['hash'];
        }

        return null;
    }
}
