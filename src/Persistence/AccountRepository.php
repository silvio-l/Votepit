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
    /**
     * Cooldown before a tombstoned account slug becomes registrable again —
     * migrations/0030_add_slug_tombstones.sql, review-2026-09-04-fixes item
     * 3. Deliberately generous: long enough that a departed tenant's old
     * links/bookmarks/QR codes have gone stale before the slug can be
     * re-claimed by someone else (link/trust hijack protection).
     */
    private const SLUG_TOMBSTONE_COOLDOWN_DAYS = 30;

    public function __construct(private Connection $conn) {}

    /**
     * Records a tombstone for a just-deleted account slug so it can't be
     * immediately re-registered by a new tenant. MUST be called BEFORE the
     * account row is actually deleted (closes the race window between the
     * DELETE and a concurrent create() grabbing the freshly-freed slug —
     * once the tombstone exists, isSlugTombstoned() blocks new
     * registrations regardless of whether the old row is still present).
     *
     * @throws DbalException
     */
    private function tombstoneSlug(string $slug): void
    {
        $this->conn->insert('slug_tombstones', [
            'scope'      => 'account',
            'account_id' => null,
            'slug'       => $slug,
            'expires_at' => (new \DateTimeImmutable('+' . self::SLUG_TOMBSTONE_COOLDOWN_DAYS . ' days'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @throws DbalException
     */
    private function isSlugTombstoned(string $slug): bool
    {
        $row = $this->conn->fetchOne(
            "SELECT 1 FROM slug_tombstones WHERE scope = 'account' AND slug = :slug AND expires_at > :now LIMIT 1",
            ['slug' => $slug, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );

        return $row !== false;
    }

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
                    onboarding_completed_at, telemetry_opted_in, telemetry_decided_at,
                    deletion_scheduled_at, deletion_reminder_sent_at,
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
     * the caller translates that into a 422 validation error. Same null
     * return for a slug still cooling down in slug_tombstones (item 3) — the
     * caller can't distinguish "taken" from "recently freed", which is
     * intentional (no tombstone-existence leak).
     *
     * @return int|null The new account ID, or null on a slug collision/tombstone.
     * @throws DbalException
     * @phpstan-impure
     */
    public function create(string $slug, string $name, string $plan, ?\DateTimeImmutable $confirmedAt = null): ?int
    {
        if ($this->isSlugTombstoned($slug)) {
            return null;
        }

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
     * Renames an account's name and/or slug (account owner self-service —
     * AccountRenameAction). Mirrors BoardRepository::renameBoard() exactly:
     * tombstones the old slug BEFORE the UPDATE (closes the same race
     * window as create()/deleteAccount()), UNIQUE(slug) constraint stays
     * active as a race backstop.
     *
     * @return bool false on a slug collision/tombstone (or unknown account),
     *              true on success.
     * @throws DbalException
     */
    public function renameAccount(int $id, string $newSlug, string $newName): bool
    {
        $current = $this->conn->fetchAssociative(
            'SELECT slug FROM accounts WHERE id = :id',
            ['id' => $id],
        );
        if ($current === false) {
            return false;
        }

        $oldSlug     = (string) $current['slug'];
        $slugChanged = $oldSlug !== $newSlug;

        if ($slugChanged) {
            if ($this->isSlugTombstoned($newSlug)) {
                return false;
            }
            $this->tombstoneSlug($oldSlug);
        }

        try {
            $this->conn->executeStatement(
                'UPDATE accounts SET slug = :slug, name = :name WHERE id = :id',
                ['slug' => $newSlug, 'name' => $newName, 'id' => $id],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Live-availability check for the account-rename UI (debounced
     * slug-taken feedback while typing). Slug is available if it either
     * belongs to no account, OR belongs to this account already (renaming
     * to the same slug is not "taken"). Deliberately does NOT check
     * isSlugTombstoned() — a leaked "recently freed but cooling down"
     * distinction would defeat the tombstone's purpose (same rationale as
     * create()'s generic null return), so a cooling-down slug is reported
     * available here and only rejected at actual save time.
     *
     * @throws DbalException
     */
    public function isSlugAvailable(string $slug, int $excludingAccountId): bool
    {
        $row = $this->conn->fetchOne(
            'SELECT 1 FROM accounts WHERE slug = :slug AND id != :excluding LIMIT 1',
            ['slug' => $slug, 'excluding' => $excludingAccountId],
        );

        return $row === false;
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

    /**
     * Records the Setup Wizard's telemetry-consent decision (accept OR
     * decline — both are a valid, equally final "decided", see
     * migrations/0035_add_telemetry_opt_in.sql). Idempotent: a later call
     * (e.g. an operator flipping their mind from the wizard again) simply
     * overwrites the previous decision and timestamp — unlike
     * markOnboardingCompleted(), this is NOT a "first write wins" flag.
     *
     * @throws DbalException
     */
    public function setTelemetryDecision(int $accountId, bool $optedIn): void
    {
        $this->conn->executeStatement(
            'UPDATE accounts SET telemetry_opted_in = :opted_in, telemetry_decided_at = :now WHERE id = :id',
            [
                'opted_in' => $optedIn ? 1 : 0,
                'now'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'       => $accountId,
            ],
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
     * Tombstones the slug (item 3) BEFORE deleting the row — see
     * tombstoneSlug() doc for why the order matters.
     *
     * @throws DbalException
     */
    public function deleteAccount(int $id): bool
    {
        $slug = $this->conn->fetchOne('SELECT slug FROM accounts WHERE id = :id', ['id' => $id]);
        if (is_string($slug)) {
            $this->tombstoneSlug($slug);
        }

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
     * Verified against db/schema.sql + migrations/000{1,2,3,8,9,10} and
     * 0023/0024/0026: boards, account_members, blocked_users, invites,
     * api_tokens, notifications and support_requests ALL carry
     * `ON DELETE CASCADE` back to accounts(id) (boards directly; ideas →
     * votes/comments/board_blocklist/board_smtp_settings cascade
     * transitively via boards.id; notification_reads and support_messages
     * cascade transitively via notifications.id/support_requests.id) — so
     * `DELETE FROM accounts` alone already removes every one of those rows
     * with no orphans left behind. users rows are deliberately NOT touched
     * (identity is global across accounts, ADR 0001 §2c) — only this
     * account's account_members row disappears.
     *
     * Tables owned by an extension (with their own FK to accounts(id)) are
     * the extension's responsibility — core only knows its own schema.
     *
     * abuse_reports is deliberately left alone (also ON DELETE SET NULL): it
     * is a DSA Art. 16 moderation review record, not accounts bookkeeping —
     * purging it is out of scope for this sprint.
     *
     * Tombstones the slug (item 3) BEFORE deleting the row — see
     * tombstoneSlug() doc for why the order matters.
     *
     * @throws DbalException
     */
    public function purgeExpired(int $accountId): void
    {
        $slug = $this->conn->fetchOne('SELECT slug FROM accounts WHERE id = :id', ['id' => $accountId]);
        if (is_string($slug)) {
            $this->tombstoneSlug($slug);
        }

        $this->conn->executeStatement('DELETE FROM accounts WHERE id = :id', ['id' => $accountId]);
    }
}
