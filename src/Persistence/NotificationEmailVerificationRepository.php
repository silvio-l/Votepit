<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Pending notification_email confirmation tokens (notification-preferences
 * feature, migration 0029). Same token-crypto shape as login_tokens (hash +
 * expiry + single-use), but a dedicated table because a candidate email
 * address — not yet written to `users` — has to travel with the token.
 *
 * Prepared-statements-only. Stores ONLY the SHA-256 hash (never the
 * plaintext token), same convention as LoginTokenRepository.
 */
final readonly class NotificationEmailVerificationRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Deletes every open verification for this user before inserting a new
     * one — same "no accumulation" convention as
     * LoginTokenRepository::deleteOpenForUser(): a fresh request supersedes
     * any earlier pending one instead of piling up.
     *
     * @throws DbalException
     */
    public function deleteForUser(int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM notification_email_verifications WHERE user_id = :user_id',
            ['user_id' => $userId],
        );
    }

    /** @throws DbalException */
    public function insert(int $userId, string $email, string $tokenHash, string $expiresAt): void
    {
        $this->conn->executeStatement(
            'INSERT INTO notification_email_verifications (user_id, email, token_hash, expires_at, created_at)
             VALUES (:user_id, :email, :token_hash, :expires_at, :created_at)',
            [
                'user_id'    => $userId,
                'email'      => $email,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Finds an active (not expired) verification by token hash. No
     * used_at/single-use column: deleteForUser() runs immediately on
     * successful confirmation instead (see NotificationPreferencesAction),
     * so a consumed token simply no longer exists — same end result as
     * login_tokens' used_at marker, one column fewer.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $row = $this->conn->fetchAssociative(
            'SELECT id, user_id, email, token_hash, expires_at
             FROM notification_email_verifications
             WHERE token_hash = :token_hash AND expires_at > :now',
            ['token_hash' => $tokenHash, 'now' => $now],
        );

        return $row === false ? null : $row;
    }
}
