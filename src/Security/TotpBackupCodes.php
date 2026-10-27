<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Backup codes for the TOTP 2FA flow (fallback in case the authenticator app
 * is unavailable — lost device, app reinstall without a backup).
 *
 * 10 codes, format XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX (28 alphanumeric
 * characters, a low-confusion alphabet without 0/O/1/I/L → 5 bits/char =
 * 140 bits of entropy, comfortably over the 128-bit floor), each usable
 * once. Hashed with Argon2id (PASSWORD_ARGON2ID, same primitive as
 * SetPasswordAction's password hashing) instead of a bare unsalted
 * SHA-256 checksum — a leaked code_hash table alone no longer lets an
 * attacker brute-force codes offline at SHA-256 speed. The plaintext
 * exists only transiently, directly after generation (one-time API
 * response).
 */
final class TotpBackupCodes
{
    private const CODE_COUNT     = 10;
    private const GROUP_LENGTH   = 4;
    private const GROUP_COUNT    = 7; // 28 chars total, 140 bits of entropy
    private const ALPHABET       = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // without 0/O/1/I/L

    /** @return list<string> 10 fresh plaintext codes (format XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX). */
    public function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $codes[] = $this->generateOne();
        }

        return $codes;
    }

    private function generateOne(): string
    {
        $group = static function (): string {
            $chars = '';
            for ($i = 0; $i < TotpBackupCodes::GROUP_LENGTH; $i++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            return $chars;
        };

        $groups = [];
        for ($i = 0; $i < self::GROUP_COUNT; $i++) {
            $groups[] = $group();
        }

        return implode('-', $groups);
    }

    /** Argon2id hash of a plaintext code (self-salting, for the DB). Normalized (uppercase, trim) before hashing. */
    public function hash(string $code): string
    {
        // password_hash() with PASSWORD_ARGON2ID never returns false per the
        // current PHPStan stubs (unlike some other algo/cost combinations).
        return password_hash($this->normalize($code), PASSWORD_ARGON2ID);
    }

    /**
     * Verifies a plaintext code against a stored hash — either the current
     * Argon2id format, or a legacy unsalted-SHA-256 hash from before this
     * migration. Legacy codes stay valid indefinitely (no breaking change
     * for a user who never regenerates); the only way to move a user's
     * codes onto Argon2id is TotpBackupCodeRepository::regenerate(), which
     * always issues fresh codes in the current format.
     */
    public function verify(string $code, string $hash): bool
    {
        $normalized = $this->normalize($code);

        if (str_starts_with($hash, '$argon2')) {
            return password_verify($normalized, $hash);
        }

        return hash_equals(hash('sha256', $normalized), $hash);
    }

    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
