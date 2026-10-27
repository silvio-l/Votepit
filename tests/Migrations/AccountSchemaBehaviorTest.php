<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Behavior comparison for the account target schema (accounts, account_members,
 * boards.account_id/status) against the SQLite test harness from
 * IntegrationTestCase — the same portable test seam as the rest of the
 * persistence tests (no MySQL process needed, see the IntegrationTestCase docblock).
 *
 * The MySQL-specific migration DDL itself (ALTER ... AFTER, DROP INDEX,
 * CHECK constraint) is checked content-only in AccountSchemaMigrationTest
 * (analogous to BaselineMigrationTest) and was additionally manually verified
 * end-to-end against a real MySQL 8 instance (empty DB, self-host backfill
 * scenario, idempotency, UNIQUE(account_id, slug) across two accounts — all green).
 *
 * This class tests the resulting INVARIANTS that account scoping in the
 * repository layer relies on.
 */
final class AccountSchemaBehaviorTest extends IntegrationTestCase
{
    public function test_exactly_one_default_account_exists_after_setup(): void
    {
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM accounts WHERE is_default = 1'));
    }

    /**
     * Self-host existing-data scenario (analogous to the real 0003 backfill
     * migration): a board inserted without an explicit account_id lands
     * automatically on the default account — insertBoard() remains callable
     * unchanged for the 454 existing tests.
     */
    public function test_board_inserted_without_explicit_account_id_lands_on_default_account(): void
    {
        $boardId = $this->insertBoard('legacy-board');

        $accountId = (int) $this->conn->fetchOne('SELECT account_id FROM boards WHERE id = ?', [$boardId]);
        $defaultId = $this->defaultAccountId();

        self::assertSame($defaultId, $accountId);
    }

    public function test_unique_account_id_slug_allows_the_same_slug_across_different_accounts(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acct-a', 'name' => 'acct-a']);
        $accountB = $this->insertAccount(['slug' => 'acct-b', 'name' => 'acct-b']);

        $this->insertBoard('shared-slug', ['account_id' => $accountA]);
        $this->insertBoard('shared-slug', ['account_id' => $accountB]);

        self::assertSame(2, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM boards WHERE slug = 'shared-slug'",
        ));
    }

    public function test_unique_account_id_slug_still_rejects_the_same_slug_within_one_account(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acct-a', 'name' => 'acct-a']);
        $this->insertBoard('dup-slug', ['account_id' => $accountA]);

        $this->expectException(\Throwable::class);
        $this->insertBoard('dup-slug', ['account_id' => $accountA]);
    }

    /**
     * boards.status exists with the documented default 'active'
     * (0002_add_boards_account_id.sql) — pure schema mirroring, no
     * PHP currently reads/writes it.
     */
    public function test_boards_status_defaults_to_active(): void
    {
        $boardId = $this->insertBoard('status-default');

        self::assertSame('active', $this->conn->fetchOne('SELECT status FROM boards WHERE id = ?', [$boardId]));
    }
}
