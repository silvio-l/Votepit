<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Account membership persistence.
 *
 * Prepared-statements-only via DBAL. account_members(account_id, user_id, role)
 * is the sole source of account roles (owner | moderator) — separate from
 * users.is_admin (platform/operator level, see AuthZMiddleware).
 */
final readonly class AccountMemberRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Returns a user's role in an account, or null if no
     * membership exists.
     *
     * @throws DbalException
     */
    public function roleFor(int $accountId, int $userId): ?string
    {
        $role = $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :account_id AND user_id = :user_id',
            ['account_id' => $accountId, 'user_id' => $userId],
        );

        return $role === false ? null : (string) $role;
    }

    /**
     * Adds a user as a member of an account. Idempotent: if the user is
     * already a member (PRIMARY KEY account_id+user_id), the role is updated
     * instead of throwing an error.
     *
     * @throws DbalException
     */
    public function addMember(int $accountId, int $userId, string $role): void
    {
        try {
            $this->conn->insert('account_members', [
                'account_id' => $accountId,
                'user_id'    => $userId,
                'role'       => $role,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Already a member — update the role idempotently instead of erroring.
            $this->conn->executeStatement(
                'UPDATE account_members SET role = :role WHERE account_id = :account_id AND user_id = :user_id',
                ['role' => $role, 'account_id' => $accountId, 'user_id' => $userId],
            );
        }
    }

    /**
     * Returns all members of an account.
     * No PII (ADR 0002): only user_id + role + created_at, no email —
     * the DB never knows the plaintext at this point anyway.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT user_id, role, created_at FROM account_members
             WHERE account_id = :account_id ORDER BY created_at ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * Counts all members (owner + moderator) of an account.
     * Account-scoped via WHERE account_id.
     *
     * @throws DbalException
     */
    public function countForAccount(int $accountId): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM account_members WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );
    }

    /**
     * Counts the owners of an account — the "at least one owner" invariant is
     * enforced by the actions (Remove/Role-Change) based on this value.
     *
     * @throws DbalException
     */
    public function countOwners(int $accountId): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM account_members WHERE account_id = :account_id AND role = 'owner'",
            ['account_id' => $accountId],
        );
    }

    /**
     * Returns slug + role of every account $userId is a member of: this gives
     * the SPA enough information in the bootstrap payload to determine
     * client-side the account role for the currently
     * requested `/{account}` slug, without misusing is_admin (platform
     * flag) as an account-owner gate.
     *
     * @return list<array{account_slug: string, role: string}>
     * @throws DbalException
     */
    public function membershipsWithSlugFor(int $userId): array
    {
        /** @var list<array{account_slug: string, role: string}> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.slug AS account_slug, am.role
             FROM account_members am
             INNER JOIN accounts a ON a.id = am.account_id
             WHERE am.user_id = :user_id',
            ['user_id' => $userId],
        );

        return $rows;
    }

    /**
     * Checks whether a user is already a member of ANY account (regardless of
     * role) — the "one account per signup" guard: SignupAccountAction
     * structurally rejects a second account for the same user, instead of
     * allowing multi-workspace ownership.
     *
     * @throws DbalException
     */
    public function hasAnyMembership(int $userId): bool
    {
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM account_members WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        return ((int) $count) > 0;
    }

    /**
     * Upgrade/downgrade/cancellation lifecycle: accounts $userId
     * OWNS (role='owner') that have a pending cancellation grace period whose
     * export-reminder mail has not gone out yet (deletion_scheduled_at IS NOT
     * NULL AND deletion_reminder_sent_at IS NULL). Used by AppFactory's
     * POST /login handler — the ONLY place in this codebase where the
     * account owner's plaintext email is transiently available (ADR 0002: no
     * plaintext email is ever persisted) — to piggy-back the reminder mail
     * onto the next magic-link request instead of trying to send it from the
     * (email-less) server-side event that scheduled the deletion.
     *
     * @return list<array{account_id: int, deletion_scheduled_at: string}>
     * @throws DbalException
     */
    public function ownedAccountsPendingReminder(int $userId): array
    {
        /** @var list<array{account_id: int|string, deletion_scheduled_at: string}> $rows */
        $rows = $this->conn->fetchAllAssociative(
            "SELECT am.account_id, a.deletion_scheduled_at
             FROM account_members am
             INNER JOIN accounts a ON a.id = am.account_id
             WHERE am.user_id = :user_id AND am.role = 'owner'
                   AND a.deletion_scheduled_at IS NOT NULL AND a.deletion_reminder_sent_at IS NULL",
            ['user_id' => $userId],
        );

        return array_map(
            static fn (array $row): array => [
                'account_id'             => (int) $row['account_id'],
                'deletion_scheduled_at'  => $row['deletion_scheduled_at'],
            ],
            $rows,
        );
    }

    /**
     * Removes a user from an account.
     * Account-scoped: a foreign account_id/user_id pair matches no row.
     * Returns false if no matching membership existed.
     *
     * @throws DbalException
     */
    public function removeMember(int $accountId, int $userId): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM account_members WHERE account_id = :account_id AND user_id = :user_id',
            ['account_id' => $accountId, 'user_id' => $userId],
        );

        return $affected > 0;
    }
}
