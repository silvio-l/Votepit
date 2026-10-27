<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * `voter_count` on the idea payloads (board list, idea detail, roadmap; the
 * widget list and GET /api/v1/ideas reuse the list query). It is computed as
 * ideas.up_count + ideas.down_count in the same SELECT, so it costs no extra
 * statement (QueryBudgetTest stays unchanged).
 *
 * Under the vote identity model (one row per signed-in account or guest
 * browser per idea, withdrawal deletes the row) that sum is the number of
 * distinct voters. These tests drive votes through VoteRepository so the
 * denormalised counters are maintained exactly as in production.
 */
final class IdeaVoterCountTest extends IntegrationTestCase
{
    private VoteRepository $votes;
    private IdeaRepository $ideas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->votes = new VoteRepository($this->conn);
        $this->ideas = new IdeaRepository($this->conn);
    }

    /** @return array<string, mixed> */
    private function detail(int $boardId, int $ideaId): array
    {
        $row = $this->ideas->findInBoard($boardId, $ideaId);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<int, array<string, mixed>> keyed by idea id */
    private function listed(int $boardId): array
    {
        $byId = [];
        foreach ($this->ideas->listByBoard($boardId) as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    public function test_zero_votes_give_a_voter_count_of_zero(): void
    {
        $boardId  = $this->insertBoard('vc-zero');
        $authorId = $this->insertUser('vc-zero-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        self::assertSame(0, (int) $this->detail($boardId, $ideaId)['voter_count']);
        self::assertSame(0, (int) $this->listed($boardId)[$ideaId]['voter_count']);
    }

    public function test_voter_count_counts_signed_in_and_guest_identities_on_both_paths(): void
    {
        $boardId  = $this->insertBoard('vc-mixed');
        $authorId = $this->insertUser('vc-mixed-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $this->votes->cast($boardId, $ideaId, $this->insertUser('vc-mixed-a@example.com'), 1);
        $this->votes->cast($boardId, $ideaId, $this->insertUser('vc-mixed-b@example.com'), -1);
        $this->votes->castAnonymous($boardId, $ideaId, 'vc-guest-token', 1);

        self::assertSame(3, (int) $this->detail($boardId, $ideaId)['voter_count']);
        self::assertSame(3, (int) $this->listed($boardId)[$ideaId]['voter_count']);
    }

    public function test_guest_only_votes_count_as_distinct_voters_on_both_paths(): void
    {
        // P-02 (17a): an idea whose every vote is a guest vote still reports its voters,
        // so the low-participation rule sees three distinct voters, not zero.
        $boardId  = $this->insertBoard('vc-guests');
        $authorId = $this->insertUser('vc-guests-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        $this->votes->castAnonymous($boardId, $ideaId, 'vc-guest-a', 1);
        $this->votes->castAnonymous($boardId, $ideaId, 'vc-guest-b', 1);
        $this->votes->castAnonymous($boardId, $ideaId, 'vc-guest-c', -1);

        self::assertSame(3, (int) $this->detail($boardId, $ideaId)['voter_count']);
        self::assertSame(3, (int) $this->listed($boardId)[$ideaId]['voter_count']);
    }

    public function test_switching_a_vote_keeps_the_voter_count_and_withdrawing_lowers_it(): void
    {
        $boardId  = $this->insertBoard('vc-switch');
        $authorId = $this->insertUser('vc-switch-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $voter    = $this->insertUser('vc-switch-voter@example.com');

        $this->votes->cast($boardId, $ideaId, $voter, 1);
        $this->votes->cast($boardId, $ideaId, $voter, -1);
        self::assertSame(1, (int) $this->detail($boardId, $ideaId)['voter_count'], 'switching up to down is still one voter');

        $this->votes->cast($boardId, $ideaId, $voter, -1);
        self::assertSame(0, (int) $this->detail($boardId, $ideaId)['voter_count'], 'retracting the vote removes the voter');
    }

    public function test_roadmap_rows_carry_the_voter_count(): void
    {
        $boardId  = $this->insertBoard('vc-roadmap');
        $authorId = $this->insertUser('vc-roadmap-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Planned idea', ['status' => 'planned']);
        $this->votes->castAnonymous($boardId, $ideaId, 'vc-roadmap-token', 1);
        $this->votes->castAnonymous($boardId, $ideaId, 'vc-roadmap-token-2', -1);

        $groups = $this->ideas->roadmapByBoard($boardId);
        self::assertCount(1, $groups['planned']);
        self::assertSame(2, (int) $groups['planned'][0]['voter_count']);
    }

    public function test_foreign_board_cannot_read_the_idea_or_its_voter_count(): void
    {
        $ownerBoard   = $this->insertBoard('vc-owner');
        $foreignBoard = $this->insertBoard('vc-foreign');
        $authorId     = $this->insertUser('vc-tenant-author@example.com');
        $ideaId       = $this->seedIdea($ownerBoard, $authorId);
        $this->votes->castAnonymous($ownerBoard, $ideaId, 'vc-tenant-token', 1);

        self::assertNull($this->ideas->findInBoard($foreignBoard, $ideaId));
        self::assertArrayNotHasKey($ideaId, $this->listed($foreignBoard));
        self::assertSame(1, (int) $this->detail($ownerBoard, $ideaId)['voter_count']);
    }
}
