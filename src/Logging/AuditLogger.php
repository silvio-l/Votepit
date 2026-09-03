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
     * Masks email addresses (and fields whose names suggest PII).
     * Recursive for nested arrays.
     *
     * @param  array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function mask(array $data): array
    {
        $piiKeys = ['email', 'mail', 'from', 'to', 'token', 'password', 'secret', 'app_key'];

        $out = [];
        foreach ($data as $k => $v) {
            $lower = strtolower((string) $k);
            if (in_array($lower, $piiKeys, true) && is_string($v) && $v !== '') {
                $out[$k] = str_contains($v, '@') ? $this->maskEmail($v) : '***';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $this->mask($v);
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
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
