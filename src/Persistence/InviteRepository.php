<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Invite persistence (roles & invitations).
 *
 * Account-scoped, hashed-token pending invitation. Mirrors LoginTokenRepository's
 * hashed-token/expiry pattern 1:1 (same TokenVault crypto) — NOT a second token
 * scheme. Prepared-statements-only.
 */
final readonly class InviteRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Deletes all open (pending, neither used nor revoked) invites for the
     * target user in this account. Called best-effort before every new insert,
     * so a re-invite invalidates the old token instead of piling up (analogous to
     * LoginTokenRepository::deleteOpenForUser()).
     *
     * @throws DbalException
     */
    public function deleteOpenForAccountUser(int $accountId, int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM invites
             WHERE account_id = :account_id AND user_id = :user_id
               AND used_at IS NULL AND revoked_at IS NULL',
            ['account_id' => $accountId, 'user_id' => $userId],
        );
    }

    /**
     * Stores a new invite row. Pass ONLY the hash — never the
     * plaintext token.
     *
     * @throws DbalException
     */
    public function insert(
        int $accountId,
        int $userId,
        int $invitedBy,
        string $tokenHash,
        string $expiresAt,
        string $role = 'moderator',
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'INSERT INTO invites (account_id, user_id, invited_by, role, token_hash, expires_at, used_at, revoked_at, created_at)
             VALUES (:account_id, :user_id, :invited_by, :role, :token_hash, :expires_at, NULL, NULL, :created_at)',
            [
                'account_id' => $accountId,
                'user_id'    => $userId,
                'invited_by' => $invitedBy,
                'role'       => $role,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ],
        );
    }

    /**
     * Finds an active (pending, not expired) invite by hash — across
     * account boundaries, since the token itself is the capability (analogous to
     * LoginTokenRepository::findActiveByHash()). The caller additionally checks
     * that the logged-in user == invites.user_id (no accepting on behalf of someone else).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, user_id, invited_by, role, token_hash, expires_at, used_at, revoked_at
             FROM invites
             WHERE token_hash = :token_hash AND used_at IS NULL AND revoked_at IS NULL AND expires_at > :now',
            ['token_hash' => $tokenHash, 'now' => $now],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds a RECENTLY accepted invite by hash — the same mail-security-
     * gateway prescanning compensation as LoginTokenRepository::findRecentlyUsedByHash()
     * (see there). InviteAcceptAction::addMember()/markUsed() are both idempotent,
     * so a replay within the grace window is inconsequential.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findRecentlyUsedByHash(string $tokenHash, int $graceSeconds): ?array
    {
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $graceSeconds))->format('Y-m-d H:i:s');

        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, user_id, invited_by, role, token_hash, expires_at, used_at, revoked_at
             FROM invites
             WHERE token_hash = :token_hash AND used_at IS NOT NULL AND used_at > :cutoff AND revoked_at IS NULL',
            ['token_hash' => $tokenHash, 'cutoff' => $cutoff],
        );

        return $row === false ? null : $row;
    }

    /**
     * Marks an invite as accepted (used_at = now). One-time-use backstop.
     *
     * @throws DbalException
     */
    public function markUsed(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'UPDATE invites SET used_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $id],
        );
    }

    /**
     * Revokes a pending invite (account-scoped — foreign account/invite ID
     * → no match, no cross-tenant leak). Returns false if no
     * matching, still-open invite existed (already used/revoked/foreign).
     *
     * @throws DbalException
     */
    public function revoke(int $id, int $accountId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->conn->executeStatement(
            'UPDATE invites SET revoked_at = :now
             WHERE id = :id AND account_id = :account_id
               AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $now, 'id' => $id, 'account_id' => $accountId],
        );

        return $affected > 0;
    }

    /**
     * Checks whether an active membership or an already-invited
     * user already exists — NOT necessary if addMember() stays idempotent;
     * still useful for the "already a member" validation in the action.
     *
     * Returns all pending invites of an account (for the member overview).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listPendingForAccount(int $accountId): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, user_id, role, expires_at, created_at
             FROM invites
             WHERE account_id = :account_id AND used_at IS NULL AND revoked_at IS NULL AND expires_at > :now
             ORDER BY created_at DESC',
            ['account_id' => $accountId, 'now' => $now],
        );

        return $rows;
    }

    /**
     * Lists the FULL invite history of an account (pending + used +
     * revoked + expired) for the account self-export. Unlike
     * listPendingForAccount() (only open, non-expired invites for the
     * member UI), there is no time/status filter here — GDPR portability
     * requires the complete history. NEVER returns token_hash (no
     * plaintext token, no hash — both stay internal, analogous to
     * ApiTokenRepository::listForBoard()).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, user_id, invited_by, role, expires_at, used_at, revoked_at, created_at
             FROM invites
             WHERE account_id = :account_id
             ORDER BY created_at DESC',
            ['account_id' => $accountId],
        );

        return $rows;
    }
}
