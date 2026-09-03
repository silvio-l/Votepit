<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /{board}/roadmap.
 *
 * All assertions run through the HTTP seam (AppFactory::create),
 * identical pipeline to production: SecurityHeader → RateLimit(IP) →
 * Session → AuthN → AuthZ anon → BlockCheck → CSRF.
 *
 * AC coverage:
 *   AC1: endpoint returns the board's own ideas grouped by {planned,in_progress,done},
 *        with aggregates (score, up_count, down_count, comment_count), no voter PII.
 *   AC2: open/declined ideas do NOT appear in the response.
 *   AC3: cross-board isolation: an idea from board A never appears in board B's roadmap.
 *   AC4: unknown board slug → 404.
 *   AC5: anon access allowed (no login required).
 *   AC6: ideas within each group sorted descending by score_cache.
 */
final class RoadmapEndpointTest extends IntegrationTestCase
{
    private function getRoadmap(string $slug): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $slug . '/roadmap');

        return $this->createApp()->handle($request);
    }

    // -------------------------------------------------------------------------
    // AC4: unknown slug → 404
    // -------------------------------------------------------------------------

    public function test_unknown_board_returns_404(): void
    {
        $response = $this->getRoadmap('no-such-board');

        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('not_found', $data['error']['key'] ?? null);
    }

    // -------------------------------------------------------------------------
    // AC5: anon access allowed + base structure
    // -------------------------------------------------------------------------

    public function test_anon_access_returns_200_with_correct_structure(): void
    {
        $boardId = $this->insertBoard('rm-anon');

        $response = $this->getRoadmap('rm-anon');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('board', $data);
        self::assertArrayHasKey('groups', $data);
        self::assertArrayHasKey('planned', $data['groups']);
        self::assertArrayHasKey('in_progress', $data['groups']);
        self::assertArrayHasKey('done', $data['groups']);
        self::assertSame('rm-anon', $data['board']['slug'] ?? null);
        self::assertSame($boardId, $data['board']['id'] ?? null);
    }

    // -------------------------------------------------------------------------
    // AC2: open/declined do NOT appear
    // -------------------------------------------------------------------------

    public function test_open_ideas_do_not_appear_in_roadmap(): void
    {
        $boardId  = $this->insertBoard('rm-open');
        $authorId = $this->insertUser('author-open@example.com');
        $this->seedIdea($boardId, $authorId, 'Open idea', ['status' => 'open']);

        $data = json_decode((string) $this->getRoadmap('rm-open')->getBody(), true);

        self::assertCount(0, $data['groups']['planned']);
        self::assertCount(0, $data['groups']['in_progress']);
        self::assertCount(0, $data['groups']['done']);
    }

    public function test_declined_ideas_do_not_appear_in_roadmap(): void
    {
        $boardId  = $this->insertBoard('rm-decl');
        $authorId = $this->insertUser('author-decl@example.com');
        $this->seedIdea($boardId, $authorId, 'Declined idea', ['status' => 'declined']);

        $data = json_decode((string) $this->getRoadmap('rm-decl')->getBody(), true);

        self::assertCount(0, $data['groups']['planned']);
        self::assertCount(0, $data['groups']['in_progress']);
        self::assertCount(0, $data['groups']['done']);
    }

    // -------------------------------------------------------------------------
    // AC1: grouping + aggregates (no PII)
    // -------------------------------------------------------------------------

    public function test_planned_idea_appears_in_planned_group(): void
    {
        $boardId  = $this->insertBoard('rm-plan');
        $authorId = $this->insertUser('author-plan@example.com');
        $this->seedIdea($boardId, $authorId, 'Planned idea', ['status' => 'planned']);

        $data = json_decode((string) $this->getRoadmap('rm-plan')->getBody(), true);

        self::assertCount(1, $data['groups']['planned']);
        self::assertSame('Planned idea', $data['groups']['planned'][0]['title'] ?? null);
        self::assertSame('planned', $data['groups']['planned'][0]['status'] ?? null);
    }

    public function test_in_progress_idea_appears_in_in_progress_group(): void
    {
        $boardId  = $this->insertBoard('rm-inp');
        $authorId = $this->insertUser('author-inp@example.com');
        $this->seedIdea($boardId, $authorId, 'In progress', ['status' => 'in_progress']);

        $data = json_decode((string) $this->getRoadmap('rm-inp')->getBody(), true);

        self::assertCount(1, $data['groups']['in_progress']);
        self::assertSame('in_progress', $data['groups']['in_progress'][0]['status'] ?? null);
    }

    public function test_done_idea_appears_in_done_group(): void
    {
        $boardId  = $this->insertBoard('rm-done');
        $authorId = $this->insertUser('author-done@example.com');
        $this->seedIdea($boardId, $authorId, 'Done', ['status' => 'done']);

        $data = json_decode((string) $this->getRoadmap('rm-done')->getBody(), true);

        self::assertCount(1, $data['groups']['done']);
        self::assertSame('done', $data['groups']['done'][0]['status'] ?? null);
    }

    public function test_aggregates_include_score_and_counts_but_no_pii(): void
    {
        $boardId  = $this->insertBoard('rm-agg');
        $authorId = $this->insertUser('author-agg@example.com');
        $voter    = $this->insertUser('voter-agg@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Aggregate idea', [
            'status'      => 'planned',
            'score_cache' => 3,
        ]);
        $this->seedVote($ideaId, $voter, 1);

        $data = json_decode((string) $this->getRoadmap('rm-agg')->getBody(), true);
        $row  = $data['groups']['planned'][0] ?? [];

        // Expected fields present
        self::assertArrayHasKey('id', $row);
        self::assertArrayHasKey('title', $row);
        self::assertArrayHasKey('body', $row);
        self::assertArrayHasKey('status', $row);
        self::assertArrayHasKey('score_cache', $row);
        self::assertArrayHasKey('comment_count', $row);
        self::assertArrayHasKey('up_count', $row);
        self::assertArrayHasKey('down_count', $row);

        // Voter PII must NOT appear
        self::assertArrayNotHasKey('author_id', $row, 'author_id must not appear in the roadmap.');
        self::assertArrayNotHasKey('my_vote', $row, 'my_vote is voter PII and must not appear.');
        self::assertArrayNotHasKey('user_id', $row, 'user_id must not appear in the roadmap.');

        // up_count from the votes table
        self::assertSame(1, (int) $row['up_count']);
        self::assertSame(0, (int) $row['down_count']);
    }

    // -------------------------------------------------------------------------
    // AC3: cross-board isolation
    // -------------------------------------------------------------------------

    public function test_cross_board_isolation_idea_from_board_a_not_in_board_b(): void
    {
        $boardA   = $this->insertBoard('rm-board-a');
        $this->insertBoard('rm-board-b');
        $authorId = $this->insertUser('author-xboard@example.com');

        $this->seedIdea($boardA, $authorId, 'Idea in board A', ['status' => 'planned']);

        $data = json_decode((string) $this->getRoadmap('rm-board-b')->getBody(), true);

        self::assertCount(0, $data['groups']['planned'] ?? [], 'Board B roadmap must not contain board A ideas.');
        self::assertCount(0, $data['groups']['in_progress'] ?? []);
        self::assertCount(0, $data['groups']['done'] ?? []);
    }

    public function test_cross_board_isolation_only_own_board_ideas_returned(): void
    {
        $boardA   = $this->insertBoard('rm-own-a');
        $boardB   = $this->insertBoard('rm-own-b');
        $authorId = $this->insertUser('author-own@example.com');

        $this->seedIdea($boardA, $authorId, 'Board A idea', ['status' => 'done']);
        $this->seedIdea($boardB, $authorId, 'Board B idea', ['status' => 'done']);

        $dataA = json_decode((string) $this->getRoadmap('rm-own-a')->getBody(), true);
        $dataB = json_decode((string) $this->getRoadmap('rm-own-b')->getBody(), true);

        self::assertCount(1, $dataA['groups']['done']);
        self::assertSame('Board A idea', $dataA['groups']['done'][0]['title'] ?? null);

        self::assertCount(1, $dataB['groups']['done']);
        self::assertSame('Board B idea', $dataB['groups']['done'][0]['title'] ?? null);
    }

    // -------------------------------------------------------------------------
    // AC6: sorting within each group by score_cache DESC
    // -------------------------------------------------------------------------

    public function test_ideas_within_group_sorted_by_score_desc(): void
    {
        $boardId  = $this->insertBoard('rm-sort');
        $authorId = $this->insertUser('author-sort@example.com');

        $this->seedIdea($boardId, $authorId, 'Low', ['status' => 'planned', 'score_cache' => 2]);
        $this->seedIdea($boardId, $authorId, 'High', ['status' => 'planned', 'score_cache' => 10]);
        $this->seedIdea($boardId, $authorId, 'Medium', ['status' => 'planned', 'score_cache' => 5]);

        $data = json_decode((string) $this->getRoadmap('rm-sort')->getBody(), true);
        $titles = array_column($data['groups']['planned'], 'title');

        self::assertSame(['High', 'Medium', 'Low'], $titles, 'Ideas must be sorted by score_cache descending.');
    }

    // -------------------------------------------------------------------------
    // Pin sorting does NOT affect the roadmap
    // -------------------------------------------------------------------------

    public function test_pinning_does_not_affect_roadmap_order(): void
    {
        $boardId  = $this->insertBoard('rm-pin');
        $authorId = $this->insertUser('author-rm-pin@example.com');

        $this->seedIdea($boardId, $authorId, 'Low', ['status' => 'planned', 'score_cache' => 2]);
        $this->seedIdea($boardId, $authorId, 'High, but unpinned', ['status' => 'planned', 'score_cache' => 10]);
        $this->seedIdea($boardId, $authorId, 'Low, but pinned', [
            'status'      => 'planned',
            'score_cache' => 1,
            'is_pinned'   => 1,
        ]);

        $data   = json_decode((string) $this->getRoadmap('rm-pin')->getBody(), true);
        $titles = array_column($data['groups']['planned'], 'title');

        // Still sorted exclusively by score_cache DESC — pin status ignored.
        self::assertSame(
            ['High, but unpinned', 'Low', 'Low, but pinned'],
            $titles,
            'roadmapByBoard remains unaffected by pin sorting (score_cache DESC).',
        );
    }

    // -------------------------------------------------------------------------
    // Empty groups — board with no roadmap-eligible ideas
    // -------------------------------------------------------------------------

    public function test_empty_board_returns_empty_groups(): void
    {
        $this->insertBoard('rm-empty');

        $data = json_decode((string) $this->getRoadmap('rm-empty')->getBody(), true);

        self::assertSame([], $data['groups']['planned']);
        self::assertSame([], $data['groups']['in_progress']);
        self::assertSame([], $data['groups']['done']);
    }
}
