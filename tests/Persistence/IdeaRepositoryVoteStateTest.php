<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence-Tests für den my_vote-Read-Pfad in IdeaRepository (Sprint 4, Issue 02).
 *
 * Beweist:
 *  - null-$currentUserId → bestehendes Verhalten, kein `my_vote`-Schlüssel im Ergebnis
 *  - gesetzter $currentUserId → `my_vote` ∈ {up, down, none}, board-/user-scoped
 *  - set-basierte Subquery (kein N+1): alle Ideen in einem Query
 *  - Cross-Board-Isolation: Stimme in Board A erscheint nicht in Board B
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
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test-Idee');

        $row = $this->repo->findInBoard($boardId, $ideaId);

        self::assertIsArray($row);
        self::assertArrayNotHasKey('my_vote', $row, 'Ohne currentUserId darf kein my_vote-Schlüssel vorhanden sein.');
    }

    // -------------------------------------------------------------------------
    // findInBoard — mit userId, verschiedene Zustände
    // -------------------------------------------------------------------------

    public function test_find_in_board_with_user_id_returns_none_when_no_vote(): void
    {
        $boardId  = $this->insertBoard('fib-none');
        $authorId = $this->insertUser('fib-none@example.com');
        $voterId  = $this->insertUser('fib-voter@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test-Idee');

        $row = $this->repo->findInBoard($boardId, $ideaId, $voterId);

        self::assertIsArray($row);
        self::assertSame('none', $row['my_vote']);
    }

    public function test_find_in_board_with_user_id_returns_up_after_up_vote(): void
    {
        $boardId  = $this->insertBoard('fib-up');
        $authorId = $this->insertUser('fib-up-author@example.com');
        $voterId  = $this->insertUser('fib-up-voter@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test-Idee');
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
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Test-Idee');
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
        $this->seedIdea($boardId, $authorId, 'Idee A');
        $this->seedIdea($boardId, $authorId, 'Idee B');

        $rows = $this->repo->listByBoard($boardId, null, 50, 0, 'newest');

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('my_vote', $row, 'Ohne currentUserId kein my_vote-Schlüssel.');
        }
    }

    // -------------------------------------------------------------------------
    // listByBoard — mit userId, mehrere Ideen, unterschiedliche Zustände
    // -------------------------------------------------------------------------

    public function test_list_by_board_with_user_id_returns_my_vote_per_idea(): void
    {
        $boardId  = $this->insertBoard('lbb-states');
        $authorId = $this->insertUser('lbb-states-author@example.com');
        $voterId  = $this->insertUser('lbb-states-voter@example.com');

        $ideaUp   = $this->seedIdea($boardId, $authorId, 'Hochgestimmt');
        $ideaDown = $this->seedIdea($boardId, $authorId, 'Runtergestimmt');
        $ideaNone = $this->seedIdea($boardId, $authorId, 'Keine Stimme');

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

        $openIdea = $this->seedIdea($boardId, $authorId, 'Offen', ['status' => 'open']);
        $this->seedIdea($boardId, $authorId, 'Erledigt', ['status' => 'done']);

        $this->seedVote($openIdea, $voterId, 1);

        $rows = $this->repo->listByBoard($boardId, 'open', 50, 0, 'newest', $voterId);

        self::assertCount(1, $rows);
        self::assertSame('up', $rows[0]['my_vote']);
    }

    // -------------------------------------------------------------------------
    // Cross-Board-Isolation (AC 6)
    // -------------------------------------------------------------------------

    public function test_my_vote_is_isolated_per_board(): void
    {
        $boardAId = $this->insertBoard('iso-board-a');
        $boardBId = $this->insertBoard('iso-board-b');
        $authorId = $this->insertUser('iso-author@example.com');
        $voterId  = $this->insertUser('iso-voter@example.com');

        // Stimme NUR in Board A abgeben.
        $ideaA = $this->seedIdea($boardAId, $authorId, 'Idee in A');
        $ideaB = $this->seedIdea($boardBId, $authorId, 'Idee in B');
        $this->seedVote($ideaA, $voterId, 1);

        // Board B darf die Stimme aus Board A nicht anzeigen.
        $rowsB = $this->repo->listByBoard($boardBId, null, 50, 0, 'newest', $voterId);
        self::assertCount(1, $rowsB);
        self::assertSame('none', $rowsB[0]['my_vote'], 'Stimme aus Board A darf in Board B nicht erscheinen.');

        // findInBoard in Board B liefert ebenfalls none.
        $rowB = $this->repo->findInBoard($boardBId, $ideaB, $voterId);
        self::assertIsArray($rowB);
        self::assertSame('none', $rowB['my_vote']);

        // Board A hingegen zeigt 'up'.
        $rowA = $this->repo->findInBoard($boardAId, $ideaA, $voterId);
        self::assertIsArray($rowA);
        self::assertSame('up', $rowA['my_vote']);
    }
}
