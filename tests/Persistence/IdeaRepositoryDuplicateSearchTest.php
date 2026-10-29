<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for IdeaRepository::findDuplicateCandidates
 * (duplicate detection, the recall half of "FULLTEXT recall + Jaro–Winkler rerank").
 *
 * Runs against the SQLite test DB, which doesn't know InnoDB FULLTEXT (see
 * IntegrationTestCase::applySchema()) — so it covers the portable fallback path
 * (bounded board-scoped fetch). The MySQL MATCH-AGAINST path is structurally
 * secured by the existing `AbstractMySQLPlatform` branch-convention precedent
 * (RateLimiter, SmtpSettingsRepository); the board-scoping and board-scoped
 * recall properties are identical for both branches, since both use the same
 * `WHERE board_id = :board_id` binding.
 */
final class IdeaRepositoryDuplicateSearchTest extends IntegrationTestCase
{
    private IdeaRepository $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sut = new IdeaRepository($this->conn);
    }

    public function test_recall_returns_ideas_from_the_given_board_only(): void
    {
        $boardId  = $this->insertBoard('demo');
        $otherBoardId = $this->insertBoard('other');
        $author   = $this->insertUser();

        $this->seedIdea($boardId, $author, 'Dark Mode for Dashboard');
        $this->seedIdea($otherBoardId, $author, 'Dark Mode for Dashboard');

        $rows = $this->sut->findDuplicateCandidates($boardId, 'Dark Mode for Dashboard');

        // Two ideas with the identical title exist across both boards — recall
        // must return exactly the one belonging to $boardId, never both.
        self::assertCount(1, $rows);
        self::assertSame('Dark Mode for Dashboard', $rows[0]['title']);
    }

    public function test_duplicate_in_another_account_never_surfaces(): void
    {
        $defaultBoardId  = $this->insertBoard('demo');
        $foreignAccountId = $this->insertAccount(['slug' => 'foreign-acct']);
        $foreignBoardId   = $this->insertBoard('foreign-board', ['account_id' => $foreignAccountId]);
        $author = $this->insertUser();

        $this->seedIdea($foreignBoardId, $author, 'Dark Mode for Dashboard');

        $rows = $this->sut->findDuplicateCandidates($defaultBoardId, 'Dark Mode for Dashboard');

        self::assertSame([], $rows);
    }

    public function test_recall_rows_expose_fields_needed_for_reranking_and_display(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser();
        $ideaId  = $this->seedIdea($boardId, $author, 'Dark Mode for Dashboard');
        $this->seedVote($ideaId, $author, 1);

        $rows = $this->sut->findDuplicateCandidates($boardId, 'Dark Mode for Dashboard');

        self::assertCount(1, $rows);
        self::assertSame($ideaId, (int) $rows[0]['id']);
        self::assertSame('Dark Mode for Dashboard', $rows[0]['title']);
        self::assertArrayHasKey('title_normalized', $rows[0]);
        self::assertSame('open', $rows[0]['status']);
        self::assertSame(1, (int) $rows[0]['up_count']);
        self::assertSame(0, (int) $rows[0]['down_count']);
    }

    public function test_recall_respects_limit(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser();

        for ($i = 0; $i < 5; $i++) {
            $this->seedIdea($boardId, $author, "Idea number {$i}");
        }

        $rows = $this->sut->findDuplicateCandidates($boardId, 'Idea number', 3);

        self::assertCount(3, $rows);
    }

    public function test_empty_board_returns_no_candidates(): void
    {
        $boardId = $this->insertBoard('demo');

        $rows = $this->sut->findDuplicateCandidates($boardId, 'Dark Mode for Dashboard');

        self::assertSame([], $rows);
    }
}
