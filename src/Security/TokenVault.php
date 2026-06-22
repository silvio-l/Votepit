<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Magic-Link-Token-Krypto (arch.md §4 — TokenVault).
 *
 * Erzeugt kryptografisch starke Einmal-Tokens und deren SHA-256-Hash. In der
 * DB (login_tokens.token_hash, CHAR(64)) wird NUR der Hash gespeichert; der
 * Klartext-Token geht ausschließlich in den Magic-Link und wird nie geloggt
 * (security.md — PII). Verifikation läuft konstant-zeitig via hash_equals.
 *
 * Einmaligkeit (used_at) und TTL (expires_at) werden auf der Persistenz-Ebene
 * (Sprint 2: login_tokens) erzwungen — dieser Helper ist reine Token-Krypto.
 */
final class TokenVault
{
    private const TOKEN_BYTES = 32; // → 64 hex-Zeichen Klartext-Token

    /**
     * Erzeugt ein Token-Paar: Klartext (für den Link) + SHA-256-Hash (für die DB).
     *
     * @return array{token: string, hash: string}
     */
    public function generate(): array
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        return ['token' => $token, 'hash' => $this->hash($token)];
    }

    /** SHA-256-Hex eines Klartext-Tokens (passt auf login_tokens.token_hash CHAR(64)). */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Konstant-zeitiger Vergleich eines Kandidaten-Tokens gegen einen
     * gespeicherten Hash. Timing-Angriff-resistent (hash_equals).
     */
    public function verify(string $candidate, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($candidate));
    }
}
