<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für die "Diese Woche"-Aggregate in GET /{board}
 * (FeaturedIdeaCard-Panel). Alle Assertions laufen durch den HTTP-Seam.
 *
 * Abgedeckt:
 *  - stats-Block vorhanden (weekly_votes / weekly_new_ideas / avg_consensus)
 *  - weekly_votes zählt nur Stimmen der letzten 7 Tage
 *  - avg_consensus = ⌀ der Pro-Idee-Zustimmungsquote
 *  - Board-Scoping: fremde Boards lecken nicht in die Kennzahlen
 *  - leeres Board → alle Werte 0
 */
final class BoardStatsTest extends IntegrationTestCase
{
    private function getBoardRequest(string $slug): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/' . $slug);
    }

    /** Fügt eine Stimme mit explizitem created_at ein (für das 7-Tage-Fenster). */
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
        $idea    = $this->seedIdea($boardId, $u1, 'Idee mit Stimmen');

        // 3 up, 1 down → Konsens 75 %
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
        $idea    = $this->seedIdea($boardId, $u1, 'Idee mit alter Stimme');

        // 1 frische Stimme (created_at = jetzt) + 1 alte (vor 10 Tagen)
        $this->seedVote($idea, $u1, 1);
        $old = (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s');
        $this->seedVoteAt($idea, $u2, 1, $old);

        $data = $this->decode($this->createApp()->handle($this->getBoardRequest('stats-window')));

        self::assertSame(1, $data['stats']['weekly_votes'], 'Alte Stimmen dürfen nicht zählen.');
    }

    public function test_avg_consensus_is_averaged_across_ideas(): void
    {
        $boardId = $this->insertBoard('stats-avg');
        $u1      = $this->insertUser('a1@example.com');
        $u2      = $this->insertUser('a2@example.com');
        $u3      = $this->insertUser('a3@example.com');
        $u4      = $this->insertUser('a4@example.com');

        // Idee 1: 3 up / 1 down = 75 %
        $idea1 = $this->seedIdea($boardId, $u1, 'Idee A');
        $this->seedVote($idea1, $u1, 1);
        $this->seedVote($idea1, $u2, 1);
        $this->seedVote($idea1, $u3, 1);
        $this->seedVote($idea1, $u4, -1);

        // Idee 2: 1 up / 1 down = 50 %
        $idea2 = $this->seedIdea($boardId, $u1, 'Idee B');
        $this->seedVote($idea2, $u1, 1);
        $this->seedVote($idea2, $u2, -1);

        $data = $this->decode($this->createApp()->handle($this->getBoardRequest('stats-avg')));

        // (75 + 50) / 2 = 62,5 → kaufmännisch gerundet 63
        self::assertSame(63, $data['stats']['avg_consensus']);
    }

    public function test_stats_are_board_scoped(): void
    {
        $boardA = $this->insertBoard('scope-a');
        $this->insertBoard('scope-b');
        $u1 = $this->insertUser('sc1@example.com');
        $u2 = $this->insertUser('sc2@example.com');

        $ideaA = $this->seedIdea($boardA, $u1, 'Nur in A');
        $this->seedVote($ideaA, $u1, 1);
        $this->seedVote($ideaA, $u2, 1);

        // Board B hat keine Ideen/Stimmen → alle Kennzahlen 0, kein Leak aus A.
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
