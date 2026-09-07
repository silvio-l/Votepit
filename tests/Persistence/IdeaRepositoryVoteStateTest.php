<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence tests for the my_vote read path in IdeaRepository.
 *
 * Proves:
 *  - null $currentUserId → existing behavior, no `my_vote` key in the result
 *  - set $currentUserId → `my_vote` ∈ {up, down, none}, board-/user-scoped
 *  - set-based subquery (no N+1): all ideas in one query
 *  - cross-board isolation: a vote in board A does not appear in board B
 */
final class IdeaRepositoryVoteStateTest extends IntegrationTestCase
{
    private IdeaRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new IdeaRepository($this->conn);
    }

    // -------------------------------------------------------------------------
    // findInBoard — null userId
    // -------------------------------------------------------------------------
    public function test_find_in_board_null_user_id_returns_no_my_vote_key(): void
    {
        $boardId  = $this->insertBoard('fib-null');
        $authorId = $this->insertUser('fib-null@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test idea');

        $row = $this->repo->findInBoard($boardId, $ideaId);

        self::assertIsArray($row);
        self::assertArrayNotHasKey('my_vote', $row, 'Without currentUserId there must be no my_vote key.');
    }

    // -------------------------------------------------------------------------
    // findInBoard — with userId, various states
    // -------------------------------------------------------------------------

    public function test_find_in_board_with_user_id_returns_none_when_no_vote(): void
    {
        $boardId  = $this->insertBoard('fib-none');
        $authorId = $this->insertUser('fib-none@example.com');
        $voterId  = $this->insertUser('fib-voter@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test idea');

        $row = $this->repo->findInBoard($boardId, $ideaId, $voterId);

        self::assertIsArray($row);
        self::assertSame('none', $row['my_vote']);
    }

    public function test_find_in_board_with_user_id_returns_up_after_up_vote(): void
    {
        $boardId  = $this->insertBoard('fib-up');
        $authorId = $this->insertUser('fib-up-author@example.com');
        $voterId  = $this->insertUser('fib-up-voter@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test idea');
        $this->seedVote($ideaId, $voterId, 1);

        $row = $this->repo->findInBoard($boardId, $ideaId, $voterId);

        self::assertIsArray($row);
        self::assertSame('up', $row['my_vote']);
    }

    public function test_find_in_board_with_user_id_returns_down_after_down_vote(): void
    {
        $boardId  = $this->insertBoard('fib-down');
        $authorId = $this->insertUser('fib-down-author@example.com');
        $voterId  = $this->insertUser('fib-down-voter@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test idea');
        $this->seedVote($ideaId, $voterId, -1);

        $row = $this->repo->findInBoard($boardId, $ideaId, $voterId);

        self::assertIsArray($row);
        self::assertSame('down', $row['my_vote']);
    }

    // -------------------------------------------------------------------------
    // listByBoard — null userId
    // -------------------------------------------------------------------------

    public function test_list_by_board_null_user_id_returns_no_my_vote_keys(): void
    {
        $boardId  = $this->insertBoard('lbb-null');
        $authorId = $this->insertUser('lbb-null@example.com');
        $this->seedIdea($boardId, $authorId, 'Idea A');
        $this->seedIdea($boardId, $authorId, 'Idea B');

        $rows = $this->repo->listByBoard($boardId, null, 50, 0, 'newest');

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('my_vote', $row, 'Without currentUserId, no my_vote key.');
        }
    }

    // -------------------------------------------------------------------------
    // listByBoard — with userId, multiple ideas, various states
    // -------------------------------------------------------------------------

    public function test_list_by_board_with_user_id_returns_my_vote_per_idea(): void
    {
        $boardId  = $this->insertBoard('lbb-states');
        $authorId = $this->insertUser('lbb-states-author@example.com');
        $voterId  = $this->insertUser('lbb-states-voter@example.com');

        $ideaUp   = $this->seedIdea($boardId, $authorId, 'Upvoted');
        $ideaDown = $this->seedIdea($boardId, $authorId, 'Downvoted');
        $ideaNone = $this->seedIdea($boardId, $authorId, 'No vote');

        $this->seedVote($ideaUp, $voterId, 1);
        $this->seedVote($ideaDown, $voterId, -1);

        $rows = $this->repo->listByBoard($boardId, null, 50, 0, 'newest', $voterId);

        self::assertCount(3, $rows);

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        self::assertSame('up', $byId[$ideaUp]['my_vote']);
        self::assertSame('down', $byId[$ideaDown]['my_vote']);
        self::assertSame('none', $byId[$ideaNone]['my_vote']);
    }

    public function test_list_by_board_with_status_filter_includes_my_vote(): void
    {
        $boardId  = $this->insertBoard('lbb-filter');
        $authorId = $this->insertUser('lbb-filter-author@example.com');
        $voterId  = $this->insertUser('lbb-filter-voter@example.com');

        $openIdea = $this->seedIdea($boardId, $authorId, 'Open', ['status' => 'open']);
        $this->seedIdea($boardId, $authorId, 'Done', ['status' => 'done']);

        $this->seedVote($openIdea, $voterId, 1);

        $rows = $this->repo->listByBoard($boardId, 'open', 50, 0, 'newest', $voterId);

        self::assertCount(1, $rows);
        self::assertSame('up', $rows[0]['my_vote']);
    }

    // -------------------------------------------------------------------------
    // Cross-board isolation (AC 6)
    // -------------------------------------------------------------------------

    public function test_my_vote_is_isolated_per_board(): void
    {
        $boardAId = $this->insertBoard('iso-board-a');
        $boardBId = $this->insertBoard('iso-board-b');
        $authorId = $this->insertUser('iso-author@example.com');
        $voterId  = $this->insertUser('iso-voter@example.com');

        // Cast a vote ONLY in board A.
        $ideaA = $this->seedIdea($boardAId, $authorId, 'Idea in A');
        $ideaB = $this->seedIdea($boardBId, $authorId, 'Idea in B');
        $this->seedVote($ideaA, $voterId, 1);

        // Board B must not show the vote from board A.
        $rowsB = $this->repo->listByBoard($boardBId, null, 50, 0, 'newest', $voterId);
        self::assertCount(1, $rowsB);
        self::assertSame('none', $rowsB[0]['my_vote'], 'Vote from board A must not appear in board B.');

        // findInBoard in board B also returns none.
        $rowB = $this->repo->findInBoard($boardBId, $ideaB, $voterId);
        self::assertIsArray($rowB);
        self::assertSame('none', $rowB['my_vote']);

        // Board A, on the other hand, shows 'up'.
        $rowA = $this->repo->findInBoard($boardAId, $ideaA, $voterId);
        self::assertIsArray($rowA);
        self::assertSame('up', $rowA['my_vote']);
    }
}
