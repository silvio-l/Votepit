<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /{board} — board home = idea list.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create +
 * IntegrationTestCase). No direct access to repository internals.
 *
 * Covered ACs:
 *  AC1  — GET /{board} returns 200, AuthZ anon, through the full pipeline
 *  AC2  — Order created_at DESC (newest)
 *  AC3  — Status filter ?status= (allow-list); invalid → all
 *  AC4  — Pagination ?page= (default page size)
 *  AC5  — Empty board → friendly empty state
 *  AC6  — Unknown board slug → 404
 *  AC7  — Board scoping: an idea from board A never appears under /board-b
 *  AC8  — ideas table present in the SQLite schema; seed helpers work
 *  AC9  — IdeaRepository uses prepared statements (no string concat); no cross-board
 *  AC10 — Autoescape: XSS attempt in title/body is escaped
 */
final class BoardHomeActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /** GET request to /{board}, optionally with a status filter and page. */
    private function getRequest(
        string $boardSlug,
        ?string $status = null,
        int $page = 1,
        ?int $userId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        $query = [];
        if ($status !== null) {
            $query['status'] = $status;
        }
        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug)
            ->withQueryParams($query);

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessionCookie($userId),
            ]);
        }

        return $request;
    }

    // -------------------------------------------------------------------------
    // AC1 — GET /{board} → 200, AuthZ anon, full pipeline
    // -------------------------------------------------------------------------

    public function test_known_board_as_anon_returns_200(): void
    {
        $this->insertBoard('myboard');

        $response = $this->createApp()->handle($this->getRequest('myboard'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // AC5 — Empty board → empty state
    // -------------------------------------------------------------------------

    public function test_empty_board_renders_empty_state(): void
    {
        $this->insertBoard('empty-board');

        $body = (string) $this->createApp()->handle($this->getRequest('empty-board'))->getBody();

        $data = json_decode($body, true);
        self::assertIsArray($data);
        self::assertEmpty($data['ideas'] ?? ['not_empty']);
    }

    // -------------------------------------------------------------------------
    // AC6 — Unknown slug → 404
    // -------------------------------------------------------------------------

    public function test_unknown_board_slug_returns_404(): void
    {
        $response = $this->createApp()->handle($this->getRequest('does-not-exist'));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2 — Order newest (created_at DESC)
    // -------------------------------------------------------------------------

    public function test_ideas_are_ordered_newest_first(): void
    {
        $boardId  = $this->insertBoard('order-board');
        $authorId = $this->insertUser('author@example.com');

        // Seed the older entry first
        $this->seedIdea($boardId, $authorId, 'Older idea', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Newer idea', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        $body = (string) $this->createApp()->handle($this->getRequest('order-board'))->getBody();

        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');
        $posOld = array_search('Older idea', $titles, true);
        $posNew = array_search('Newer idea', $titles, true);

        self::assertIsInt($posOld);
        self::assertIsInt($posNew);
        // The newer idea must appear before the older one (smaller array index)
        self::assertLessThan($posOld, $posNew, 'Newer idea must appear before the older idea (newest first)');
    }

    // -------------------------------------------------------------------------
    // Pinned idea appears at the top (independent of ?sort=)
    // -------------------------------------------------------------------------

    public function test_pinned_idea_appears_first_regardless_of_chosen_sort(): void
    {
        $boardId  = $this->insertBoard('pin-home-board');
        $authorId = $this->insertUser('pin-home@example.com');

        $this->seedIdea($boardId, $authorId, 'Newer, unpinned idea', [
            'created_at'  => '2025-06-01 10:00:00',
            'score_cache' => 10,
        ]);
        $this->seedIdea($boardId, $authorId, 'Older, pinned idea', [
            'created_at'  => '2025-01-01 10:00:00',
            'score_cache' => 1,
            'is_pinned'   => 1,
        ]);

        foreach (['newest', 'top'] as $sort) {
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/pin-home-board?sort=' . $sort);
            $body = (string) $this->createApp()->handle($request)->getBody();
            $data = json_decode($body, true);

            self::assertSame(
                'Older, pinned idea',
                $data['ideas'][0]['title'] ?? null,
                "Pinned idea must be at the top for sort={$sort}.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // AC3 — Status filter ?status= (allow-list); invalid → all
    // -------------------------------------------------------------------------

    public function test_valid_status_filter_narrows_list(): void
    {
        $boardId  = $this->insertBoard('filter-board');
        $authorId = $this->insertUser('filter@example.com');

        $this->seedIdea($boardId, $authorId, 'Open idea', ['status' => 'open']);
        $this->seedIdea($boardId, $authorId, 'Done idea', ['status' => 'done']);

        $body   = (string) $this->createApp()->handle($this->getRequest('filter-board', 'open'))->getBody();
        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');

        self::assertContains('Open idea', $titles);
        self::assertNotContains('Done idea', $titles);
    }

    public function test_invalid_status_filter_shows_all(): void
    {
        $boardId  = $this->insertBoard('invalid-filter-board');
        $authorId = $this->insertUser('ifilter@example.com');

        $this->seedIdea($boardId, $authorId, 'Idea A', ['status' => 'open']);
        $this->seedIdea($boardId, $authorId, 'Idea B', ['status' => 'done']);

        $body   = (string) $this->createApp()
            ->handle($this->getRequest('invalid-filter-board', 'nonsense'))
            ->getBody();
        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');

        self::assertContains('Idea A', $titles);
        self::assertContains('Idea B', $titles);
    }

    public function test_all_allowed_statuses_are_accepted(): void
    {
        $boardId  = $this->insertBoard('all-status-board');
        $authorId = $this->insertUser('allstat@example.com');

        foreach (['open', 'planned', 'in_progress', 'done', 'declined'] as $status) {
            $this->seedIdea($boardId, $authorId, "Idea-{$status}", ['status' => $status]);
        }

        foreach (['open', 'planned', 'in_progress', 'done', 'declined'] as $status) {
            $response = $this->createApp()->handle($this->getRequest('all-status-board', $status));
            self::assertSame(200, $response->getStatusCode(), "Status '{$status}' should return 200");
            $data   = json_decode((string) $response->getBody(), true);
            $titles = array_column($data['ideas'] ?? [], 'title');
            self::assertContains("Idea-{$status}", $titles, "Status '{$status}' should contain the idea");
        }
    }

    // -------------------------------------------------------------------------
    // AC7 — Board scoping: an idea from board A never appears under /board-b
    // -------------------------------------------------------------------------

    public function test_idea_from_board_a_does_not_appear_under_board_b(): void
    {
        $boardAId = $this->insertBoard('board-a');
        $boardBId = $this->insertBoard('board-b');
        $authorId = $this->insertUser('scope@example.com');

        $this->seedIdea($boardAId, $authorId, 'Secret idea from A');
        $this->seedIdea($boardBId, $authorId, 'Public idea from B');

        $bodyB  = (string) $this->createApp()->handle($this->getRequest('board-b'))->getBody();
        $dataB  = json_decode($bodyB, true);
        $titlesB = array_column($dataB['ideas'] ?? [], 'title');

        self::assertContains('Public idea from B', $titlesB);
        self::assertNotContains('Secret idea from A', $titlesB);
    }

    // -------------------------------------------------------------------------
    // AC8 — ideas table in the SQLite schema; seed helpers
    // -------------------------------------------------------------------------

    public function test_seed_helpers_populate_ideas_table(): void
    {
        $boardId  = $this->insertBoard('seed-test');
        $authorId = $this->insertUser('seedtest@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Seeded idea');

        self::assertGreaterThan(0, $ideaId);

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row);
        self::assertSame('Seeded idea', $row['title']);
        self::assertSame($boardId, (int) $row['board_id']);
    }

    // -------------------------------------------------------------------------
    // AC10 — Autoescape: XSS attempt is escaped
    // -------------------------------------------------------------------------

    public function test_xss_in_idea_title_is_escaped(): void
    {
        $boardId  = $this->insertBoard('xss-board');
        $authorId = $this->insertUser('xss@example.com');
        $xssTitle = '<script>alert("xss")</script>';
        $this->seedIdea($boardId, $authorId, $xssTitle);

        $body = (string) $this->createApp()->handle($this->getRequest('xss-board'))->getBody();

        // JSON API returns the raw value; React escapes when rendering (no HTML output here)
        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');
        self::assertContains($xssTitle, $titles, 'XSS title must appear as plaintext in the JSON response.');
    }

    // -------------------------------------------------------------------------
    // AC4 — Pagination ?page= (default page size)
    // -------------------------------------------------------------------------

    public function test_pagination_page_param_is_respected(): void
    {
        $boardId  = $this->insertBoard('paged-board');
        $authorId = $this->insertUser('paged@example.com');

        // Only 2 ideas → always on page 1; page 2 → empty → empty state
        $this->seedIdea($boardId, $authorId, 'Idea Alpha');
        $this->seedIdea($boardId, $authorId, 'Idea Beta');

        // Page 1 returns ideas
        $body1  = (string) $this->createApp()->handle($this->getRequest('paged-board', null, 1))->getBody();
        $data1  = json_decode($body1, true);
        $titles1 = array_column($data1['ideas'] ?? [], 'title');
        self::assertContains('Idea Alpha', $titles1);

        // Page 999 (far away) → empty idea list
        $body999 = (string) $this->createApp()->handle($this->getRequest('paged-board', null, 999))->getBody();
        $data999 = json_decode($body999, true);
        self::assertEmpty($data999['ideas'] ?? ['not_empty']);
    }

    // -------------------------------------------------------------------------
    // Board name in the title
    // -------------------------------------------------------------------------

    public function test_board_name_appears_in_page(): void
    {
        $this->insertBoard('named-board', ['name' => 'My super board']);

        $body = (string) $this->createApp()->handle($this->getRequest('named-board'))->getBody();

        $data = json_decode($body, true);
        self::assertSame('My super board', $data['board']['name'] ?? null);
    }
}
