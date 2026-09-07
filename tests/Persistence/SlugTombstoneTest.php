<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * review-2026-09-04-fixes item 3: deleting an account/board no longer
 * immediately frees its slug back into UNIQUE(slug) — a tombstone
 * (migrations/0030_add_slug_tombstones.sql) blocks re-registration for a
 * cooldown period, so a new tenant can't inherit a departed tenant's old
 * links/bookmarks/QR codes.
 */
final class SlugTombstoneTest extends IntegrationTestCase
{
    // ── Accounts ─────────────────────────────────────────────────────────

    public function test_account_slug_is_rejected_for_reregistration_after_hard_delete(): void
    {
        $repo = new AccountRepository($this->conn);
        $id   = $repo->create('departed-tenant', 'Departed', 'self-host');
        self::assertIsInt($id);

        self::assertTrue($repo->deleteAccount($id));

        self::assertNull($repo->create('departed-tenant', 'New Owner', 'self-host'));
    }

    public function test_account_slug_is_rejected_for_reregistration_after_gdpr_purge(): void
    {
        $repo = new AccountRepository($this->conn);
        $id   = $repo->create('gdpr-purged', 'Purged', 'self-host');
        self::assertIsInt($id);

        $repo->purgeExpired($id);

        self::assertNull($repo->create('gdpr-purged', 'New Owner', 'self-host'));
    }

    public function test_account_slug_becomes_registrable_again_after_the_cooldown_expires(): void
    {
        $repo = new AccountRepository($this->conn);
        $id   = $repo->create('cooled-down', 'Gone', 'self-host');
        self::assertIsInt($id);
        $repo->deleteAccount($id);

        self::assertNull($repo->create('cooled-down', 'New Owner', 'self-host'));

        // Simulates the cooldown having elapsed: backdate the tombstone's
        // expiry (repository has no "advance time" hook — this is the
        // standard way this codebase tests window/cooldown expiry, see
        // e.g. RateLimiterPruneTest).
        $this->conn->executeStatement(
            "UPDATE slug_tombstones SET expires_at = :past WHERE scope = 'account' AND slug = 'cooled-down'",
            ['past' => (new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s')],
        );

        $newId = $repo->create('cooled-down', 'New Owner', 'self-host');
        self::assertIsInt($newId);
        self::assertNotSame($id, $newId);
    }

    public function test_unrelated_account_slug_is_unaffected_by_a_tombstone(): void
    {
        $repo = new AccountRepository($this->conn);
        $id   = $repo->create('tombstoned-one', 'Gone', 'self-host');
        self::assertIsInt($id);
        $repo->deleteAccount($id);

        self::assertIsInt($repo->create('completely-different-slug', 'Fresh', 'self-host'));
    }

    // ── Boards ───────────────────────────────────────────────────────────

    public function test_board_slug_is_rejected_for_reregistration_in_the_same_account_after_delete(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'departed-board', 'Departed Board', 'public');
        self::assertIsInt($boardId);

        self::assertTrue($repo->deleteBoard($boardId));

        self::assertNull($repo->create($accountId, 'departed-board', 'New Board', 'public'));
    }

    public function test_board_slug_tombstone_is_scoped_per_account(): void
    {
        $repo       = new BoardRepository($this->conn);
        $accountA   = $this->insertAccount();
        $accountB   = $this->insertAccount();
        $boardId    = $repo->create($accountA, 'shared-slug', 'Board A', 'public');
        self::assertIsInt($boardId);
        $repo->deleteBoard($boardId);

        self::assertNull($repo->create($accountA, 'shared-slug', 'New Board A', 'public'));
        // A different account's tombstone namespace is untouched — same
        // slug string is immediately registrable there.
        self::assertIsInt($repo->create($accountB, 'shared-slug', 'Board B', 'public'));
    }

    public function test_board_slug_becomes_registrable_again_after_the_cooldown_expires(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'cooled-board', 'Gone', 'public');
        self::assertIsInt($boardId);
        $repo->deleteBoard($boardId);

        self::assertNull($repo->create($accountId, 'cooled-board', 'New Board', 'public'));

        $this->conn->executeStatement(
            "UPDATE slug_tombstones SET expires_at = :past WHERE scope = 'board' AND slug = 'cooled-board'",
            ['past' => (new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s')],
        );

        self::assertIsInt($repo->create($accountId, 'cooled-board', 'New Board', 'public'));
    }

    // ── Board rename ─────────────────────────────────────────────────────

    public function test_renamed_away_board_slug_is_tombstoned_and_rejected_for_reregistration(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'old-name-board', 'Old Name', 'public');
        self::assertIsInt($boardId);

        self::assertTrue($repo->renameBoard($boardId, $accountId, 'new-name-board', 'New Name'));

        self::assertNull($repo->create($accountId, 'old-name-board', 'Someone Else', 'public'));
    }

    public function test_renamed_board_slug_becomes_registrable_again_after_the_cooldown_expires(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'cooled-rename-board', 'Gone', 'public');
        self::assertIsInt($boardId);
        self::assertTrue($repo->renameBoard($boardId, $accountId, 'renamed-away-board', 'Renamed'));

        self::assertNull($repo->create($accountId, 'cooled-rename-board', 'New Board', 'public'));

        $this->conn->executeStatement(
            "UPDATE slug_tombstones SET expires_at = :past WHERE scope = 'board' AND slug = 'cooled-rename-board'",
            ['past' => (new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s')],
        );

        self::assertIsInt($repo->create($accountId, 'cooled-rename-board', 'New Board', 'public'));
    }
}
