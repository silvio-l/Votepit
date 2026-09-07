<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\VoteRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * VoteRepository::countByUserForAccount — the account-scoped "votes cast"
 * count backing the public profile contribution stats (social-features
 * issue 06).
 *
 * Account-scoped via a double JOIN (votes -> ideas -> boards), since votes
 * itself carries neither board_id nor account_id — same chokepoint
 * reasoning as VoteRepository::listForAccount().
 */
final class VoteRepositoryContributionCountTest extends IntegrationTestCase
{
    private VoteRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new VoteRepository($this->conn);
    }

    public function test_counts_votes_cast_by_user_in_account(): void
    {
        $accountId = $this->defaultAccountId();
        $boardId   = $this->insertBoard('vote-contrib-board', ['account_id' => $accountId]);
        $author    = $this->insertUser('vote-contrib-author@example.com');
        $voter     = $this->insertUser('vote-contrib-voter@example.com');

        $idea1 = $this->seedIdea($boardId, $author, 'Idea one');
        $idea2 = $this->seedIdea($boardId, $author, 'Idea two');
        $this->seedVote($idea1, $voter, 1);
        $this->seedVote($idea2, $voter, -1);

        self::assertSame(2, $this->repo->countByUserForAccount($accountId, $voter));
    }

    public function test_zero_activity_returns_zero_not_null(): void
    {
        $accountId = $this->defaultAccountId();
        $voter     = $this->insertUser('vote-contrib-none@example.com');

        self::assertSame(0, $this->repo->countByUserForAccount($accountId, $voter));
    }

    /**
     * Cross-tenant invariant: votes cast in a DIFFERENT account must never
     * be counted when querying account A.
     */
    public function test_votes_in_a_different_account_are_not_counted(): void
    {
        $accountA = $this->defaultAccountId();
        $accountB = $this->insertAccount();
        $boardA   = $this->insertBoard('vote-contrib-board-a', ['account_id' => $accountA]);
        $boardB   = $this->insertBoard('vote-contrib-board-b', ['account_id' => $accountB]);
        $author   = $this->insertUser('vote-contrib-author2@example.com');
        $voter    = $this->insertUser('vote-contrib-cross-tenant@example.com');

        $ideaA = $this->seedIdea($boardA, $author, 'Idea in account A');
        $ideaB = $this->seedIdea($boardB, $author, 'Idea in account B');
        $this->seedVote($ideaA, $voter, 1);
        $this->seedVote($ideaB, $voter, 1);

        self::assertSame(1, $this->repo->countByUserForAccount($accountA, $voter));
        self::assertSame(1, $this->repo->countByUserForAccount($accountB, $voter));
    }
}
