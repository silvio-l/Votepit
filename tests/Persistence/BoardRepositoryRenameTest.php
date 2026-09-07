<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\BoardRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Unit-level coverage for BoardRepository::renameBoard() beyond the
 * tombstone-focused cases in SlugTombstoneTest — collision backstop,
 * name-only/slug-only independence, cross-tenant scoping.
 */
final class BoardRepositoryRenameTest extends IntegrationTestCase
{
    public function test_renaming_title_only_keeps_slug_unchanged_and_does_not_tombstone_it(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'stable-slug', 'Old Title', 'public');
        self::assertIsInt($boardId);

        self::assertTrue($repo->renameBoard($boardId, $accountId, 'stable-slug', 'New Title'));

        $row = $this->conn->fetchAssociative('SELECT slug, name FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($row);
        self::assertSame('stable-slug', $row['slug']);
        self::assertSame('New Title', $row['name']);

        // The slug never actually changed, so it must not have been tombstoned —
        // a later re-create attempt of the SAME slug by another board would be
        // nonsensical here (this board still owns it), but we assert the
        // tombstone table itself stays empty for it as the direct signal.
        $tombstoned = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM slug_tombstones WHERE scope = 'board' AND slug = 'stable-slug'",
        );
        self::assertSame(0, (int) $tombstoned);
    }

    public function test_renaming_slug_only_keeps_title_unchanged(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'slug-one', 'Stable Title', 'public');
        self::assertIsInt($boardId);

        self::assertTrue($repo->renameBoard($boardId, $accountId, 'slug-two', 'Stable Title'));

        $row = $this->conn->fetchAssociative('SELECT slug, name FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($row);
        self::assertSame('slug-two', $row['slug']);
        self::assertSame('Stable Title', $row['name']);
    }

    public function test_rename_to_a_slug_already_taken_in_the_same_account_fails_via_unique_backstop(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $repo->create($accountId, 'taken-by-other', 'Other Board', 'public');
        $boardId = $repo->create($accountId, 'movable-board', 'Movable', 'public');
        self::assertIsInt($boardId);

        self::assertFalse($repo->renameBoard($boardId, $accountId, 'taken-by-other', 'Movable'));

        // Unchanged — the failed rename must not have partially applied.
        $row = $this->conn->fetchAssociative('SELECT slug FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($row);
        self::assertSame('movable-board', $row['slug']);
    }

    public function test_rename_to_the_same_slug_used_by_a_different_board_in_a_different_account_succeeds(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountA  = $this->insertAccount();
        $accountB  = $this->insertAccount();
        $repo->create($accountB, 'cross-account-slug', 'Board B', 'public');
        $boardId = $repo->create($accountA, 'movable-cross', 'Movable', 'public');
        self::assertIsInt($boardId);

        self::assertTrue($repo->renameBoard($boardId, $accountA, 'cross-account-slug', 'Movable'));

        $row = $this->conn->fetchAssociative('SELECT slug FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($row);
        self::assertSame('cross-account-slug', $row['slug']);
    }

    public function test_rename_of_a_foreign_boards_id_scoped_to_the_wrong_account_returns_false(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountA  = $this->insertAccount();
        $accountB  = $this->insertAccount();
        $boardId   = $repo->create($accountA, 'owned-by-a', 'Board A', 'public');
        self::assertIsInt($boardId);

        self::assertFalse($repo->renameBoard($boardId, $accountB, 'hijack-attempt', 'Hijacked'));

        $row = $this->conn->fetchAssociative('SELECT slug, name FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($row);
        self::assertSame('owned-by-a', $row['slug']);
        self::assertSame('Board A', $row['name']);
    }
}
