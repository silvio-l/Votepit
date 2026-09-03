<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Magic link token crypto (arch.md §4 — TokenVault).
 *
 * Generates cryptographically strong one-time tokens and their SHA-256 hash.
 * In the DB (login_tokens.token_hash, CHAR(64)) ONLY the hash is stored; the
 * plaintext token goes exclusively into the magic link and is never logged
 * (security.md — PII). Verification runs constant-time via hash_equals.
 *
 * One-time use (used_at) and TTL (expires_at) are enforced at the
 * persistence layer (login_tokens) — this helper is pure token crypto.
 */
final class TokenVault
{
    private const TOKEN_BYTES = 32; // → 64 hex-character plaintext token

    /**
     * Generates a token pair: plaintext (for the link) + SHA-256 hash (for the DB).
     *
     * @return array{token: string, hash: string}
     */
    public function generate(): array
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        return ['token' => $token, 'hash' => $this->hash($token)];
    }

    /** SHA-256 hex of a plaintext token (fits login_tokens.token_hash CHAR(64)). */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Constant-time comparison of a candidate token against a
     * stored hash. Timing-attack resistant (hash_equals).
     */
    public function verify(string $candidate, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($candidate));
    }
}
