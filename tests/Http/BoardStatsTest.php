<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the "this week" aggregates in GET /{board}
 * (FeaturedIdeaCard panel). All assertions run through the HTTP seam.
 *
 * Covered:
 *  - stats block present (weekly_votes / weekly_new_ideas / avg_consensus)
 *  - weekly_votes only counts votes from the last 7 days
 *  - avg_consensus = average of the per-idea approval rate
 *  - Board scoping: foreign boards don't leak into the metrics
 *  - empty board → all values 0
 */
final class BoardStatsTest extends IntegrationTestCase
{
    private function getBoardRequest(string $slug): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/' . $slug);
    }

    /** Inserts a vote with an explicit created_at (for the 7-day window). */
    private function seedVoteAt(int $ideaId, int $userId, int $value, string $createdAt): void
    {
        $this->conn->insert('votes', [
            'idea_id'    => $ideaId,
            'user_id'    => $userId,
            'value'      => $value,
            'created_at' => $createdAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('stats', $data);
        self::assertIsArray($data['stats']);

        return $data;
    }

    public function test_stats_block_is_present_with_weekly_values(): void
    {
        $boardId = $this->insertBoard('stats-present');
        $u1      = $this->insertUser('s1@example.com');
        $u2      = $this->insertUser('s2@example.com');
        $u3      = $this->insertUser('s3@example.com');
        $u4      = $this->insertUser('s4@example.com');
        $idea    = $this->seedIdea($boardId, $u1, 'Idea with votes');

        // 3 up, 1 down → consensus 75 %
        $this->seedVote($idea, $u1, 1);
        $this->seedVote($idea, $u2, 1);
        $this->seedVote($idea, $u3, 1);
        $this->seedVote($idea, $u4, -1);

        $data = $this->decode($this->createApp()->handle($this->getBoardRequest('stats-present')));

        self::assertSame(4, $data['stats']['weekly_votes']);
        self::assertSame(1, $data['stats']['weekly_new_ideas']);
        self::assertSame(75, $data['stats']['avg_consensus']);
    }

    public function test_weekly_votes_excludes_votes_older_than_7_days(): void
    {
        $boardId = $this->insertBoard('stats-window');
        $u1      = $this->insertUser('w1@example.com');
        $u2      = $this->insertUser('w2@example.com');
        $idea    = $this->seedIdea($boardId, $u1, 'Idea with an old vote');

        // 1 fresh vote (created_at = now) + 1 old one (10 days ago)
        $this->seedVote($idea, $u1, 1);
        $old = (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s');
        $this->seedVoteAt($idea, $u2, 1, $old);

        $data = $this->decode($this->createApp()->handle($this->getBoardRequest('stats-window')));

        self::assertSame(1, $data['stats']['weekly_votes'], 'Old votes must not count.');
    }

    public function test_avg_consensus_is_averaged_across_ideas(): void
    {
        $boardId = $this->insertBoard('stats-avg');
        $u1      = $this->insertUser('a1@example.com');
        $u2      = $this->insertUser('a2@example.com');
        $u3      = $this->insertUser('a3@example.com');
        $u4      = $this->insertUser('a4@example.com');

        // Idea 1: 3 up / 1 down = 75 %
        $idea1 = $this->seedIdea($boardId, $u1, 'Idea A');
        $this->seedVote($idea1, $u1, 1);
        $this->seedVote($idea1, $u2, 1);
        $this->seedVote($idea1, $u3, 1);
        $this->seedVote($idea1, $u4, -1);

        // Idea 2: 1 up / 1 down = 50 %
        $idea2 = $this->seedIdea($boardId, $u1, 'Idea B');
        $this->seedVote($idea2, $u1, 1);
        $this->seedVote($idea2, $u2, -1);

        $data = $this->decode($this->createApp()->handle($this->getBoardRequest('stats-avg')));

        // (75 + 50) / 2 = 62.5 → rounded (commercial rounding) to 63
        self::assertSame(63, $data['stats']['avg_consensus']);
    }

    public function test_stats_are_board_scoped(): void
    {
        $boardA = $this->insertBoard('scope-a');
        $this->insertBoard('scope-b');
        $u1 = $this->insertUser('sc1@example.com');
        $u2 = $this->insertUser('sc2@example.com');

        $ideaA = $this->seedIdea($boardA, $u1, 'Only in A');
        $this->seedVote($ideaA, $u1, 1);
        $this->seedVote($ideaA, $u2, 1);

        // Board B has no ideas/votes → all metrics 0, no leak from A.
        $data = $this->decode($this->createApp()->handle($this->getBoardRequest('scope-b')));

        self::assertSame(0, $data['stats']['weekly_votes']);
        self::assertSame(0, $data['stats']['weekly_new_ideas']);
        self::assertSame(0, $data['stats']['avg_consensus']);
    }

    public function test_empty_board_returns_zero_stats(): void
    {
        $this->insertBoard('stats-empty');

        $data = $this->decode($this->createApp()->handle($this->getBoardRequest('stats-empty')));

        self::assertSame(0, $data['stats']['weekly_votes']);
        self::assertSame(0, $data['stats']['weekly_new_ideas']);
        self::assertSame(0, $data['stats']['avg_consensus']);
    }
}
