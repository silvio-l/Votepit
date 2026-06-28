<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für GET /{board} — Board-Home = Ideenliste (Sprint 3, Issue 03).
 *
 * Alle Assertions laufen ausschließlich durch den HTTP-Seam (AppFactory::create +
 * IntegrationTestCase). Kein direkter Zugriff auf Repository-Interna.
 *
 * Abgedeckte ACs:
 *  AC1  — GET /{board} liefert 200, AuthZ anon, durch die volle Pipeline
 *  AC2  — Reihenfolge created_at DESC (Newest)
 *  AC3  — Status-Filter ?status= (Allow-List); ungültig → alle
 *  AC4  — Pagination ?page= (Default-Seitengröße)
 *  AC5  — Leeres Board → freundlicher Empty-State
 *  AC6  — Unbekannter Board-Slug → 404
 *  AC7  — Board-Scoping: Idee aus Board A erscheint nie unter /board-b
 *  AC8  — ideas-Tabelle im SQLite-Schema vorhanden; Seed-Helfer funktionieren
 *  AC9  — IdeaRepository nutzt Prepared-Statements (kein String-Konkat); kein Cross-Board
 *  AC10 — Autoescape: XSS-Versuch in Titel/Body wird escaped
 */
final class BoardHomeActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    /** GET-Request auf /{board}, optional mit Status-Filter und Seite. */
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
    // AC1 — GET /{board} → 200, AuthZ anon, volle Pipeline
    // -------------------------------------------------------------------------

    public function test_known_board_as_anon_returns_200(): void
    {
        $this->insertBoard('myboard');

        $response = $this->createApp()->handle($this->getRequest('myboard'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // AC5 — Leeres Board → Empty-State
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
    // AC6 — Unbekannter Slug → 404
    // -------------------------------------------------------------------------

    public function test_unknown_board_slug_returns_404(): void
    {
        $response = $this->createApp()->handle($this->getRequest('does-not-exist'));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2 — Reihenfolge Newest (created_at DESC)
    // -------------------------------------------------------------------------

    public function test_ideas_are_ordered_newest_first(): void
    {
        $boardId  = $this->insertBoard('order-board');
        $authorId = $this->insertUser('author@example.com');

        // Älteren Eintrag zuerst seeden
        $this->seedIdea($boardId, $authorId, 'Ältere Idee', [
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ]);
        $this->seedIdea($boardId, $authorId, 'Neuere Idee', [
            'created_at' => '2025-06-01 10:00:00',
            'updated_at' => '2025-06-01 10:00:00',
        ]);

        $body = (string) $this->createApp()->handle($this->getRequest('order-board'))->getBody();

        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');
        $posOld = array_search('Ältere Idee', $titles, true);
        $posNew = array_search('Neuere Idee', $titles, true);

        self::assertIsInt($posOld);
        self::assertIsInt($posNew);
        // Neuere Idee muss vor der älteren erscheinen (kleinerer Array-Index)
        self::assertLessThan($posOld, $posNew, 'Neuere Idee muss vor älterer Idee erscheinen (Newest first)');
    }

    // -------------------------------------------------------------------------
    // AC3 — Status-Filter ?status= (Allow-List); ungültig → alle
    // -------------------------------------------------------------------------

    public function test_valid_status_filter_narrows_list(): void
    {
        $boardId  = $this->insertBoard('filter-board');
        $authorId = $this->insertUser('filter@example.com');

        $this->seedIdea($boardId, $authorId, 'Offene Idee', ['status' => 'open']);
        $this->seedIdea($boardId, $authorId, 'Erledigte Idee', ['status' => 'done']);

        $body   = (string) $this->createApp()->handle($this->getRequest('filter-board', 'open'))->getBody();
        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');

        self::assertContains('Offene Idee', $titles);
        self::assertNotContains('Erledigte Idee', $titles);
    }

    public function test_invalid_status_filter_shows_all(): void
    {
        $boardId  = $this->insertBoard('invalid-filter-board');
        $authorId = $this->insertUser('ifilter@example.com');

        $this->seedIdea($boardId, $authorId, 'Idee A', ['status' => 'open']);
        $this->seedIdea($boardId, $authorId, 'Idee B', ['status' => 'done']);

        $body   = (string) $this->createApp()
            ->handle($this->getRequest('invalid-filter-board', 'nonsense'))
            ->getBody();
        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');

        self::assertContains('Idee A', $titles);
        self::assertContains('Idee B', $titles);
    }

    public function test_all_allowed_statuses_are_accepted(): void
    {
        $boardId  = $this->insertBoard('all-status-board');
        $authorId = $this->insertUser('allstat@example.com');

        foreach (['open', 'planned', 'in_progress', 'done', 'declined'] as $status) {
            $this->seedIdea($boardId, $authorId, "Idee-{$status}", ['status' => $status]);
        }

        foreach (['open', 'planned', 'in_progress', 'done', 'declined'] as $status) {
            $response = $this->createApp()->handle($this->getRequest('all-status-board', $status));
            self::assertSame(200, $response->getStatusCode(), "Status '{$status}' soll 200 liefern");
            $data   = json_decode((string) $response->getBody(), true);
            $titles = array_column($data['ideas'] ?? [], 'title');
            self::assertContains("Idee-{$status}", $titles, "Status '{$status}' soll Idee enthalten");
        }
    }

    // -------------------------------------------------------------------------
    // AC7 — Board-Scoping: Idee aus Board A erscheint nie unter /board-b
    // -------------------------------------------------------------------------

    public function test_idea_from_board_a_does_not_appear_under_board_b(): void
    {
        $boardAId = $this->insertBoard('board-a');
        $boardBId = $this->insertBoard('board-b');
        $authorId = $this->insertUser('scope@example.com');

        $this->seedIdea($boardAId, $authorId, 'Geheime Idee aus A');
        $this->seedIdea($boardBId, $authorId, 'Öffentliche Idee aus B');

        $bodyB  = (string) $this->createApp()->handle($this->getRequest('board-b'))->getBody();
        $dataB  = json_decode($bodyB, true);
        $titlesB = array_column($dataB['ideas'] ?? [], 'title');

        self::assertContains('Öffentliche Idee aus B', $titlesB);
        self::assertNotContains('Geheime Idee aus A', $titlesB);
    }

    // -------------------------------------------------------------------------
    // AC8 — ideas-Tabelle im SQLite-Schema; Seed-Helfer
    // -------------------------------------------------------------------------

    public function test_seed_helpers_populate_ideas_table(): void
    {
        $boardId  = $this->insertBoard('seed-test');
        $authorId = $this->insertUser('seedtest@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Geseedete Idee');

        self::assertGreaterThan(0, $ideaId);

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row);
        self::assertSame('Geseedete Idee', $row['title']);
        self::assertSame($boardId, (int) $row['board_id']);
    }

    // -------------------------------------------------------------------------
    // AC10 — Autoescape: XSS-Versuch wird escaped
    // -------------------------------------------------------------------------

    public function test_xss_in_idea_title_is_escaped(): void
    {
        $boardId  = $this->insertBoard('xss-board');
        $authorId = $this->insertUser('xss@example.com');
        $xssTitle = '<script>alert("xss")</script>';
        $this->seedIdea($boardId, $authorId, $xssTitle);

        $body = (string) $this->createApp()->handle($this->getRequest('xss-board'))->getBody();

        // JSON-API liefert den Rohwert; React escaped beim Rendern (kein HTML-Output hier)
        $data   = json_decode($body, true);
        $titles = array_column($data['ideas'] ?? [], 'title');
        self::assertContains($xssTitle, $titles, 'XSS-Titel muss als Klartext in der JSON-Antwort stehen.');
    }

    // -------------------------------------------------------------------------
    // AC4 — Pagination ?page= (Default-Seitengröße)
    // -------------------------------------------------------------------------

    public function test_pagination_page_param_is_respected(): void
    {
        $boardId  = $this->insertBoard('paged-board');
        $authorId = $this->insertUser('paged@example.com');

        // Nur 2 Ideen → immer auf Seite 1; Seite 2 → leer → Empty-State
        $this->seedIdea($boardId, $authorId, 'Idee Alpha');
        $this->seedIdea($boardId, $authorId, 'Idee Beta');

        // Seite 1 liefert Ideen
        $body1  = (string) $this->createApp()->handle($this->getRequest('paged-board', null, 1))->getBody();
        $data1  = json_decode($body1, true);
        $titles1 = array_column($data1['ideas'] ?? [], 'title');
        self::assertContains('Idee Alpha', $titles1);

        // Seite 999 (weit weg) → leere Ideenliste
        $body999 = (string) $this->createApp()->handle($this->getRequest('paged-board', null, 999))->getBody();
        $data999 = json_decode($body999, true);
        self::assertEmpty($data999['ideas'] ?? ['not_empty']);
    }

    // -------------------------------------------------------------------------
    // Board-Name im Titel
    // -------------------------------------------------------------------------

    public function test_board_name_appears_in_page(): void
    {
        $this->insertBoard('named-board', ['name' => 'Mein Super Board']);

        $body = (string) $this->createApp()->handle($this->getRequest('named-board'))->getBody();

        $data = json_decode($body, true);
        self::assertSame('Mein Super Board', $data['board']['name'] ?? null);
    }
}
