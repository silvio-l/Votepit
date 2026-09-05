<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Account membership persistence.
 *
 * Prepared-statements-only via DBAL. account_members(account_id, user_id, role)
 * is the sole source of account roles (owner | admin | moderator | member) —
 * separate from users.is_admin (platform/operator level, see AuthZMiddleware).
 * Exactly one 'owner' per account, always: MemberAction::changeRole() never
 * accepts 'owner' as a target, so no code path can create a second one or
 * remove the only one. 'member' carries no admin/moderation rights at all —
 * its only effect is passing BoardRepository's private-board visibility check
 * (`roleFor(...) !== null`), i.e. it's a private-board voter, the account-
 * scoped equivalent of an anonymous voter on a public board.
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
     * No email PII (ADR 0002): user_id + role + created_at + the user's own
     * self-chosen public username (already exposed elsewhere, e.g.
     * SupportRequestAction) + public_id (migrations/0036-0038 — the
     * display-safe handle; user_id itself stays internal-only, see
     * AppFactory's bootstrap payload doc comment) — the DB never knows the
     * plaintext email anyway.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT am.user_id, am.role, am.created_at, u.username, u.public_id
             FROM account_members am
             JOIN users u ON u.id = am.user_id
             WHERE am.account_id = :account_id ORDER BY am.created_at ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * Counts all members (every role) of an account.
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
     * Checks whether a user already OWNS an account — the "one account per
     * signup" guard: SignupAccountAction structurally rejects a second
     * account for the same user, instead of allowing multi-workspace
     * ownership (ADR 0001 §2c decision 17). Deliberately role-specific
     * (owner only), not "any membership": being invited as a plain member/
     * moderator/admin of someone else's account must not block a person from
     * later starting their own paid account under the same email — those are
     * unrelated identities-in-different-roles, not "already a customer".
     *
     * @throws DbalException
     */
    public function hasOwnAccount(int $userId): bool
    {
        $count = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM account_members WHERE user_id = :user_id AND role = 'owner'",
            ['user_id' => $userId],
        );

        return ((int) $count) > 0;
    }

    /**
     * Whether the platform operator (users.is_operator, see AuthZMiddleware)
     * is a member of the given account, in any role. Used by EffectivePlan to
     * treat the operator's own account(s) as top-plan for every visitor —
     * including anonymous ones — not just when the operator personally is the
     * one making the request. Safe to call cheaply: at most one user can hold
     * is_operator = 1 at a time (see migration 0040_enforce_single_operator).
     *
     * @throws DbalException
     */
    public function isOperatorMember(int $accountId): bool
    {
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM account_members
             JOIN users ON users.id = account_members.user_id
             WHERE account_members.account_id = :account_id AND users.is_operator = 1',
            ['account_id' => $accountId],
        );

        return ((int) $count) > 0;
    }

    /**
     * Returns the account_id of the account $userId belongs to, or null if
     * they belong to none. A user can now legitimately have more than one
     * membership (their own owned account plus any number of team-member
     * roles elsewhere — see hasOwnAccount() above), so callers relying on
     * this to mean "THE account" (singular) must only do so where that's
     * actually true for their context; this deterministically returns the
     * oldest membership (ORDER BY created_at ASC) rather than an arbitrary
     * row when there is more than one.
     *
     * @throws DbalException
     */
    public function accountIdFor(int $userId): ?int
    {
        $id = $this->conn->fetchOne(
            'SELECT account_id FROM account_members WHERE user_id = :user_id ORDER BY created_at ASC LIMIT 1',
            ['user_id' => $userId],
        );

        return $id === false ? null : (int) $id;
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
