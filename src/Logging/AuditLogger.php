<?php

declare(strict_types=1);

namespace Votepit\Logging;

/**
 * Pseudonymized security audit log (security.md §8, A09).
 *
 * Writes one line per event to the log (default: logs/audit.log outside the
 * webroot; fallback error_log). PII (email addresses) is masked before
 * writing: "foo@bar.tld" → "f**@b**.tld#a1b2c3d4e5f6" (readable + correlatable
 * via the stable 12-character SHA256 suffix).
 *
 * Secrets (app_key, passwords, plaintext tokens) must NEVER go into context.
 */
final readonly class AuditLogger
{
    public function __construct(
        private string $logPath,
        private bool $enabled = true,
    ) {}

    /** @param array<string, mixed> $context */
    public function log(string $action, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        $line = sprintf(
            '[%s] %s %s',
            date(\DateTimeInterface::ATOM),
            $action,
            json_encode($this->mask($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($this->logPath !== '' && @file_put_contents($this->logPath, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
        error_log($line);
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
        // Already-pseudonymized fields (ADR-0002's `*_hmac` suffix) are safe
        // to log as-is and deliberately kept visible for correlation.
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
