<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Backup codes for the TOTP 2FA flow (fallback in case the authenticator app
 * is unavailable — lost device, app reinstall without a backup).
 *
 * 10 codes, format XXXX-XXXX (8 alphanumeric characters, a low-confusion
 * alphabet without 0/O/1/I/L), each usable once. Only the SHA-256 hash is
 * persisted (analogous to TokenVault) — the plaintext exists only
 * transiently, directly after generation (one-time API response).
 */
final class TotpBackupCodes
{
    private const CODE_COUNT     = 10;
    private const GROUP_LENGTH   = 4;
    private const ALPHABET       = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // without 0/O/1/I/L

    /** @return list<string> 10 fresh plaintext codes (format XXXX-XXXX). */
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

        return $group() . '-' . $group();
    }

    /** SHA-256 hex of a plaintext code (for the DB, analogous to TokenVault::hash). Normalized (uppercase, trim) before hashing. */
    public function hash(string $code): string
    {
        return hash('sha256', $this->normalize($code));
    }

    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
