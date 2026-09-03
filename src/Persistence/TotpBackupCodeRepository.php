<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Votepit\Security\TotpBackupCodes;

/**
 * Persistence for TOTP backup codes (Votepit\Security\TotpBackupCodes).
 *
 * Prepared-statements-only. Stores ONLY the SHA-256 hash per code — the
 * plaintext exists exclusively transiently (a one-time API response right
 * after generation), never in the DB.
 */
final readonly class TotpBackupCodeRepository
{
    public function __construct(
        private Connection $conn,
        private TotpBackupCodes $codes,
    ) {}

    /** Deletes all existing codes of the user (before regenerating — no piling up of old codes). */
    public function deleteAllForUser(int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM totp_backup_codes WHERE user_id = :user_id',
            ['user_id' => $userId],
        );
    }

    /**
     * Generates 10 fresh codes, replacing all existing ones of the user.
     *
     * @return list<string> the 10 plaintext codes (return ONLY now, never retrievable again)
     * @throws DbalException
     */
    public function regenerate(int $userId): array
    {
        $this->deleteAllForUser($userId);

        $plaintextCodes = $this->codes->generate();
        $now            = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($plaintextCodes as $code) {
            $this->conn->insert('totp_backup_codes', [
                'user_id'    => $userId,
                'code_hash'  => $this->codes->hash($code),
                'used_at'    => null,
                'created_at' => $now,
            ]);
        }

        return $plaintextCodes;
    }

    /**
     * Checks a candidate backup code against the user's stored hashes
     * and consumes it on a match (used_at = now) — each code is
     * redeemable exactly once. Returns true on success, false otherwise (NO
     * side effect on failure).
     *
     * @throws DbalException
     */
    public function verifyAndConsume(int $userId, string $code): bool
    {
        $hash = $this->codes->hash($code);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Single atomic UPDATE ... WHERE used_at IS NULL — success is decided by
        // affected-row-count, not by a prior SELECT. A separate SELECT-then-UPDATE
        // is a TOCTOU race: two concurrent requests with the same code (double
        // submit, network retry) could both see the row as unused and both get
        // `true` back, issuing two sessions from one backup code.
        $affected = $this->conn->executeStatement(
            'UPDATE totp_backup_codes SET used_at = :now
             WHERE user_id = :user_id AND code_hash = :hash AND used_at IS NULL',
            ['now' => $now, 'user_id' => $userId, 'hash' => $hash],
        );

        return $affected === 1;
    }

    /** Counts the still-remaining (unused) codes — for the profile UI ("3 of 10 remaining"). */
    public function countRemaining(int $userId): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = :user_id AND used_at IS NULL',
            ['user_id' => $userId],
        );
    }
}
