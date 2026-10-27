<?php

declare(strict_types=1);

namespace Votepit\Logging;

/**
 * Pseudonymized, tamper-evident security audit log (security.md §8, A09).
 *
 * One NDJSON object per line (OWASP/BSI audit remediation issue 11):
 * `{"ts","action","context","prev","hash"}` — machine-parseable with any
 * standard JSON tool, unlike the previous "[ISO8601] action {json}" text
 * prefix. PII (email addresses) is masked before writing: "foo@bar.tld" →
 * "f**@b**.tld#a1b2c3d4e5f6" (readable + correlatable via the stable
 * 12-character SHA256 suffix). Already-pseudonymized fields (the `_hmac`
 * suffix convention — email_hmac, ip_hmac) are passed through as-is; call
 * sites are responsible for hashing IPs via AuditIpHasher before logging —
 * an IP must never reach this class in plaintext.
 *
 * Hash chaining: `hash` is SHA-256 over the line's own `{ts,action,context,
 * prev}` (in that key order, no `hash` field yet), and `prev` is the
 * previous line's `hash` (or a 64-zero genesis value for the first line).
 * Deleting or editing any line breaks every `prev`/`hash` link after it —
 * AuditLogVerifier walks the chain and reports the first break. This is
 * detection, not prevention: an attacker with write access to the log file
 * can still rewrite the whole chain from the point of tampering onward, but
 * cannot edit or delete a single historical line without the walk noticing
 * the seam. File-level protection (permissions, off-host shipping) is the
 * complementary control — see documentation/operations.md.
 *
 * Secrets (app_key, passwords, plaintext tokens) must NEVER go into context.
 */
final readonly class AuditLogger
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private string $logPath,
        private bool $enabled = true,
    ) {}

    /** Chain-start marker for the first line ever written to a log file (64 hex zeros, sha256-shaped). */
    public static function genesisHash(): string
    {
        return str_repeat('0', 64);
    }

    /** @param array<string, mixed> $context */
    public function log(string $action, array $context = []): void
    {
        if (!$this->enabled || $this->logPath === '') {
            return;
        }

        $entry = [
            'ts'      => date(\DateTimeInterface::ATOM),
            'action'  => $action,
            'context' => $this->mask($context),
        ];

        $handle = @fopen($this->logPath, 'c+');
        if ($handle === false) {
            $this->fallback($entry, self::genesisHash());
            return;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            $this->fallback($entry, self::genesisHash());
            return;
        }

        $entry['prev'] = $this->previousHash($handle);
        $chained       = (string) json_encode($entry, self::JSON_FLAGS);
        $entry['hash'] = hash('sha256', $chained);

        fseek($handle, 0, SEEK_END);
        fwrite($handle, json_encode($entry, self::JSON_FLAGS) . "\n");
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Fallback when the log file itself can't be opened/locked (disk full,
     * permissions, ...).
     *
     * @param array{ts: string, action: string, context: array<string, mixed>} $entry
     */
    private function fallback(array $entry, string $prev): void
    {
        $entry['prev'] = $prev;
        $entry['hash'] = hash('sha256', (string) json_encode($entry, self::JSON_FLAGS));
        error_log((string) json_encode($entry, self::JSON_FLAGS));
    }

    /**
     * Reads just enough of the file's tail to recover the last line's
     * `hash` field, under the caller's already-held lock — avoids loading
     * the whole (potentially large) log file for every single write.
     *
     * @param resource $handle
     */
    private function previousHash($handle): string
    {
        $stat = fstat($handle);
        $size = $stat !== false ? $stat['size'] : 0;
        if ($size <= 0) {
            return self::genesisHash();
        }

        // 64 KiB comfortably holds the last line for any realistic audit
        // context (short scalar fields, no blobs) — if it somehow doesn't,
        // fall back to genesis rather than reading an unbounded file.
        $chunkSize = min($size, 65536);
        fseek($handle, -$chunkSize, SEEK_END);
        $tail = fread($handle, $chunkSize);
        $tail = $tail !== false ? $tail : '';

        $lines = array_values(array_filter(
            explode("\n", $tail),
            static fn (string $line): bool => trim($line) !== '',
        ));
        if ($lines === []) {
            return self::genesisHash();
        }

        $last = json_decode(end($lines), true);
        return is_array($last) && is_string($last['hash'] ?? null) ? $last['hash'] : self::genesisHash();
    }

    /**
     * Masks email addresses (and fields whose names suggest PII). Recursive
     * for nested arrays.
     *
     * Two independent layers, since key-name matching alone is a latent leak
     * risk for any future field named differently than expected: (1) a
     * substring match against the key name (catches e.g. "user_email",
     * "notification_email", "csrf_token", not just an exact "email"/"token"),
     * and (2) a value-shape check that masks any string that looks like an
     * email regardless of its key name at all.
     *
     * @param  array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function mask(array $data): array
    {
        $piiKeySubstrings = ['mail', 'token', 'password', 'secret', 'app_key'];
        $piiKeysExact     = ['from', 'to'];

        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->mask($v);
                continue;
            }
            if (is_string($v) && $v !== '') {
                if ($this->keyLooksLikePii((string) $k, $piiKeysExact, $piiKeySubstrings)) {
                    $out[$k] = str_contains($v, '@') ? $this->maskEmail($v) : '***';
                    continue;
                }
                if ($this->looksLikeEmail($v)) {
                    $out[$k] = $this->maskEmail($v);
                    continue;
                }
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * @param list<string> $exactKeys
     * @param list<string> $substrings
     */
    private function keyLooksLikePii(string $key, array $exactKeys, array $substrings): bool
    {
        $lower = strtolower($key);
        // Already-pseudonymized fields (ADR-0002's `*_hmac` suffix, also
        // used for ip_hmac — issue 11) are safe to log as-is and
        // deliberately kept visible for correlation.
        if (str_ends_with($lower, '_hmac')) {
            return false;
        }
        if (in_array($lower, $exactKeys, true)) {
            return true;
        }
        foreach ($substrings as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeEmail(string $value): bool
    {
        return str_contains($value, '@') && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $localShort  = mb_substr($local, 0, 1) . '**';
        $domainShort = mb_substr($domain, 0, 1) . '**';
        $hash        = substr(hash('sha256', strtolower($email)), 0, 12);
        return $localShort . '@' . $domainShort . '#' . $hash;
    }
}
