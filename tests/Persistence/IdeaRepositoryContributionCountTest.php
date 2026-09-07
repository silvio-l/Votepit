<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * IdeaRepository::countByAuthorForAccount — the account-scoped contribution
 * count backing the public profile's "ideas submitted" / "ideas shipped"
 * numbers (social-features issue 06).
 *
 * Account-scoped via a JOIN on boards (ideas itself carries no account_id
 * column, only board_id) — same chokepoint reasoning as
 * IdeaRepository::listAllForAccount().
 */
final class IdeaRepositoryContributionCountTest extends IntegrationTestCase
{
    private IdeaRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new IdeaRepository($this->conn);
    }

    public function test_counts_all_ideas_by_author_in_account(): void
    {
        $accountId = $this->defaultAccountId();
        $boardId   = $this->insertBoard('contrib-board', ['account_id' => $accountId]);
        $authorId  = $this->insertUser('contrib-author@example.com');

        $this->seedIdea($boardId, $authorId, 'Idea one');
        $this->seedIdea($boardId, $authorId, 'Idea two');

        self::assertSame(2, $this->repo->countByAuthorForAccount($accountId, $authorId));
    }

    public function test_counts_only_ideas_with_matching_status_when_given(): void
    {
        $accountId = $this->defaultAccountId();
        $boardId   = $this->insertBoard('contrib-board-status', ['account_id' => $accountId]);
        $authorId  = $this->insertUser('contrib-author-status@example.com');

        $this->seedIdea($boardId, $authorId, 'Open idea', ['status' => 'open']);
        $this->seedIdea($boardId, $authorId, 'Done idea 1', ['status' => 'done']);
        $this->seedIdea($boardId, $authorId, 'Done idea 2', ['status' => 'done']);

        self::assertSame(2, $this->repo->countByAuthorForAccount($accountId, $authorId, 'done'));
        self::assertSame(3, $this->repo->countByAuthorForAccount($accountId, $authorId));
    }

    public function test_zero_activity_returns_zero_not_null(): void
    {
        $accountId = $this->defaultAccountId();
        $authorId  = $this->insertUser('contrib-author-none@example.com');

        self::assertSame(0, $this->repo->countByAuthorForAccount($accountId, $authorId));
        self::assertSame(0, $this->repo->countByAuthorForAccount($accountId, $authorId, 'done'));
    }

    /**
     * Cross-tenant invariant: the same user's ideas in a DIFFERENT account
     * (different board -> different account_id) must never be counted when
     * querying account A.
     */
    public function test_ideas_in_a_different_account_are_not_counted(): void
    {
        $accountA = $this->defaultAccountId();
        $accountB = $this->insertAccount();
        $boardA   = $this->insertBoard('contrib-board-a', ['account_id' => $accountA]);
        $boardB   = $this->insertBoard('contrib-board-b', ['account_id' => $accountB]);
        $authorId = $this->insertUser('contrib-cross-tenant@example.com');

        $this->seedIdea($boardA, $authorId, 'Idea in account A');
        $this->seedIdea($boardB, $authorId, 'Idea in account B');
        $this->seedIdea($boardB, $authorId, 'Another idea in account B', ['status' => 'done']);

        self::assertSame(1, $this->repo->countByAuthorForAccount($accountA, $authorId));
        self::assertSame(2, $this->repo->countByAuthorForAccount($accountB, $authorId));
        self::assertSame(0, $this->repo->countByAuthorForAccount($accountA, $authorId, 'done'));
        self::assertSame(1, $this->repo->countByAuthorForAccount($accountB, $authorId, 'done'));
    }
}
