<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\BoardRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * findByIdForAccount() (bearer-token/API paths) must return the same board
 * shape as findBySlugForAccount() (browser paths). Otherwise id-loaded boards
 * silently lose flags such as `statuses_customized` (BoardStatusRepository::
 * registryFor() would fall back to the default statuses) or `premoderation`.
 */
final class BoardRepositoryFindByIdTest extends IntegrationTestCase
{
    public function test_find_by_id_returns_the_same_columns_as_find_by_slug(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'parity-board', 'Parity', 'public');
        self::assertIsInt($boardId);

        $bySlug = $repo->findBySlugForAccount('parity-board', $accountId);
        $byId   = $repo->findByIdForAccount($boardId, $accountId);
        self::assertIsArray($bySlug);
        self::assertIsArray($byId);

        self::assertSame(array_keys($bySlug), array_keys($byId));
    }

    public function test_find_by_id_carries_customized_statuses_and_premoderation_flags(): void
    {
        $repo      = new BoardRepository($this->conn);
        $accountId = $this->insertAccount();
        $boardId   = $repo->create($accountId, 'flagged-board', 'Flagged', 'public');
        self::assertIsInt($boardId);
        $this->conn->executeStatement(
            "UPDATE boards SET statuses_customized = 1, premoderation = 'all' WHERE id = :id",
            ['id' => $boardId],
        );

        $board = $repo->findByIdForAccount($boardId, $accountId);
        self::assertIsArray($board);
        self::assertSame(1, (int) $board['statuses_customized']);
        self::assertSame('all', $board['premoderation']);
    }

    public function test_find_by_id_stays_account_scoped(): void
    {
        $repo     = new BoardRepository($this->conn);
        $accountA = $this->insertAccount();
        $accountB = $this->insertAccount();
        $boardId  = $repo->create($accountA, 'scoped-board', 'Scoped', 'public');
        self::assertIsInt($boardId);

        self::assertNull($repo->findByIdForAccount($boardId, $accountB));
    }
}
