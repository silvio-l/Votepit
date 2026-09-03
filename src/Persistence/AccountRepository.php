<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Account persistence.
 *
 * Prepared-statements-only via DBAL. Self-hosting runs exactly one account
 * (is_default = 1, see migrations/0003_seed_default_account.sql) — the
 * cloud resolution via an {account} path segment is NOT part of this scope,
 * only the contract (AccountContextMiddleware::ATTR_ACCOUNT_ID) needs to hold.
 */
final readonly class AccountRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Returns the ID of the default account. Fail-secure: if the default account
     * is missing (should always exist thanks to migration 0003), this does NOT
     * silently return 0 but throws a meaningful exception.
     *
     * @throws DbalException
     */
    public function defaultAccountId(): int
    {
        $id = $this->conn->fetchOne('SELECT id FROM accounts WHERE is_default = 1 LIMIT 1');

        if ($id === false) {
            throw new \RuntimeException(
                'AccountRepository::defaultAccountId: no default account found. '
                . 'Expected from migrations/0003_seed_default_account.sql — have all migrations been run?',
            );
        }

        return (int) $id;
    }

    /**
     * Finds an account by its ID.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, slug, name, plan, board_limit, member_limit, is_default, confirmed_at, locked_at,
                    onboarding_completed_at, deletion_scheduled_at, deletion_reminder_sent_at,
                    created_at
             FROM accounts WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds an account by its slug — the cloud routing chokepoint
     * (cloud path routing): AccountContextMiddleware resolves the
     * {account} path segment exclusively via this in cloud mode.
     * Unknown slug → null (caller decides on 404).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, slug, name, plan, board_limit, member_limit, is_default, confirmed_at, locked_at,
                    onboarding_completed_at, created_at
             FROM accounts WHERE slug = :slug',
            ['slug' => $slug],
        );

        return $row === false ? null : $row;
    }

    /**
     * Creates a new account (cloud signup onboarding —
     * POST /signup/account). $confirmedAt is written 1:1 into accounts.confirmed_at
     * (NULL = this account's boards are not yet publicly
     * visible, see migrations/0011_add_account_confirmed_at.sql +
     * BoardRepository::findPublicBySlugForAccount()).
     *
     * Race backstop analogous to BoardRepository::create(): if the INSERT
     * fails due to the UNIQUE(slug) constraint (slug collision), this
     * method returns null instead of letting a 500 exception propagate —
     * the caller translates that into a 422 validation error.
     *
     * @return int|null The new account ID, or null on a slug collision.
     * @throws DbalException
     */
    public function create(string $slug, string $name, string $plan, ?\DateTimeImmutable $confirmedAt = null): ?int
    {
        try {
            $this->conn->insert('accounts', [
                'slug'         => $slug,
                'name'         => $name,
                'plan'         => $plan,
                'is_default'   => 0,
                'confirmed_at' => $confirmedAt?->format('Y-m-d H:i:s'),
                'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return null;
        }

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Marks the Setup Wizard (BoardsAdminPage.tsx) as done — either completed
     * or explicitly skipped, the SPA doesn't distinguish the two server-side.
     * Idempotent: a second call is a silent no-op (WHERE ... IS NULL), so the
     * wizard's "Done"/"Skip" actions can be retried safely without
     * clobbering the original completion timestamp.
     *
     * @throws DbalException
     */
    public function markOnboardingCompleted(int $accountId): void
    {
        $this->conn->executeStatement(
            'UPDATE accounts SET onboarding_completed_at = :now WHERE id = :id AND onboarding_completed_at IS NULL',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $accountId],
        );
    }

    // -------------------------------------------------------------------------
    // Operator panel — platform-wide operator actions. Every
    // method below is account_id-scoped by ID (never by slug — the operator
    // acts on a specific account regardless of which tenant owns it), and is
    // meant to be called ONLY from behind AuthZMiddleware::operator().
    // -------------------------------------------------------------------------

    /**
     * Sets accounts.locked_at = now (reversible — see unlockAccount()).
     * Extends BoardRepository::findPublicBySlugForAccount(), the ONE
     * visibility chokepoint, instead of introducing a second parallel
     * check.
     *
     * @throws DbalException
     */
    public function lockAccount(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE accounts SET locked_at = :now WHERE id = :id',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /**
     * Sets accounts.locked_at = NULL — lifts an operator lock again.
     *
     * @throws DbalException
     */
    public function unlockAccount(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE accounts SET locked_at = NULL WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Hard-deletes an account. ON DELETE CASCADE (boards, account_members,
     * invites — see db/schema.sql + migrations/000{1,9}) automatically cleans up
     * everything hanging off it; users remain untouched (identity
     * is global, ADR 0001 §2c). Returns false if the ID did not exist.
     *
     * @throws DbalException
     */
    public function deleteAccount(int $id): bool
    {
        $affected = $this->conn->executeStatement('DELETE FROM accounts WHERE id = :id', ['id' => $id]);

        return $affected > 0;
    }

    /**
     * Lists ALL accounts platform-wide (operator overview) —
     * deliberately WITHOUT account-scoping WHERE, which is the whole point of this
     * route: an operator must be able to see every account, regardless of who
     * owns it.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForOperator(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, slug, name, plan, is_default, confirmed_at, locked_at, created_at
             FROM accounts ORDER BY created_at DESC',
        );

        return $rows;
    }

    /**
     * Counts accounts grouped by plan.
     *
     * @return array<string, int> plan => count
     * @throws DbalException
     */
    public function countByPlan(): array
    {
        /** @var list<array{plan: mixed, c: int|string}> $rows */
        $rows = $this->conn->fetchAllAssociative('SELECT plan, COUNT(*) AS c FROM accounts GROUP BY plan');

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['plan']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Counts all accounts platform-wide.
     *
     * @throws DbalException
     */
    public function countAll(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM accounts');
    }

    // -------------------------------------------------------------------------
    // Scheduled account deletion — grace period + export reminder, and the
    // eventual cleanup-script cascade delete. Callers: AccountDeleteAction
    // (owner self-service, schedule/clear), extensions that react to
    // external lifecycle events, and bin/cleanup-expired-accounts.php
    // (findExpiredForDeletion/purgeExpired).
    // -------------------------------------------------------------------------

    /**
     * Starts a deletion grace period: sets accounts.deletion_scheduled_at =
     * $deadline. Callers that may be invoked repeatedly for the same event
     * (e.g. a replayed webhook) must only call this when
     * deletion_scheduled_at is not already set — otherwise a freshly
     * computed deadline would silently push the deletion out indefinitely.
     * deletion_reminder_sent_at is reset to NULL here too — a fresh
     * schedule always gets a fresh reminder mail.
     *
     * @throws DbalException
     */
    public function scheduleDeletion(int $accountId, \DateTimeImmutable $deadline): void
    {
        $this->conn->update('accounts', [
            'deletion_scheduled_at'     => $deadline->format('Y-m-d H:i:s'),
            'deletion_reminder_sent_at' => null,
        ], ['id' => $accountId]);
    }

    /**
     * Clears a pending cancellation (resubscription before the grace period
     * elapsed) — both deletion_scheduled_at and deletion_reminder_sent_at go
     * back to NULL. Idempotent no-op if nothing was scheduled.
     *
     * @throws DbalException
     */
    public function clearDeletionSchedule(int $accountId): void
    {
        $this->conn->update('accounts', [
            'deletion_scheduled_at'     => null,
            'deletion_reminder_sent_at' => null,
        ], ['id' => $accountId]);
    }

    /**
     * Marks the export-reminder mail as sent (AppFactory's POST /login
     * handler, the first time the account owner's plaintext email becomes
     * available again after cancellation — see that handler's class doc for
     * why the reminder cannot be sent directly from the webhook: ADR 0002
     * means no plaintext email is ever persisted, only email_hmac).
     *
     * @throws DbalException
     */
    public function markDeletionReminderSent(int $accountId): void
    {
        $this->conn->update(
            'accounts',
            ['deletion_reminder_sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ['id' => $accountId],
        );
    }

    /**
     * Finds every account whose grace period has fully elapsed
     * (deletion_scheduled_at <= $now) — the cleanup script's work queue
     * (bin/cleanup-expired-accounts.php). An account with deletion_scheduled_at
     * still in the future is deliberately excluded (untouched until its own
     * deadline passes).
     *
     * @return list<array{id: int, slug: string, deletion_scheduled_at: string}>
     * @throws DbalException
     */
    public function findExpiredForDeletion(\DateTimeImmutable $now): array
    {
        /** @var list<array{id: int|string, slug: string, deletion_scheduled_at: string}> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, slug, deletion_scheduled_at FROM accounts
             WHERE deletion_scheduled_at IS NOT NULL AND deletion_scheduled_at <= :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): array => [
                'id'                     => (int) $row['id'],
                'slug'                   => $row['slug'],
                'deletion_scheduled_at'  => $row['deletion_scheduled_at'],
            ],
            $rows,
        );
    }

    /**
     * Completely, cascadingly purges one expired account
     * (bin/cleanup-expired-accounts.php — never called from an HTTP action).
     *
     * Verified against db/schema.sql + migrations/000{1,2,3,8,9,10}: boards,
     * account_members, blocked_users, invites and api_tokens ALL carry
     * `ON DELETE CASCADE` back to accounts(id) (boards directly; ideas →
     * votes/comments/board_blocklist/board_smtp_settings cascade transitively
     * via boards.id) — so `DELETE FROM accounts` alone already removes every
     * one of those rows with no orphans left behind. users rows are
     * deliberately NOT touched (identity is global across accounts, ADR 0001
     * §2c) — only this account's account_members row disappears.
     *
     * Tables owned by an extension (with their own FK to accounts(id)) are
     * the extension's responsibility — core only knows its own schema.
     *
     * abuse_reports is deliberately left alone (also ON DELETE SET NULL): it
     * is a DSA Art. 16 moderation review record, not accounts bookkeeping —
     * purging it is out of scope for this sprint.
     *
     * @throws DbalException
     */
    public function purgeExpired(int $accountId): void
    {
        $this->conn->executeStatement('DELETE FROM accounts WHERE id = :id', ['id' => $accountId]);
    }
}
