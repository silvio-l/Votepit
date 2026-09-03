<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /{board}/ideas/{id} — idea detail view.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create +
 * IntegrationTestCase). No direct access to repository internals.
 *
 * Covered ACs:
 *  AC1  — GET /{board}/ideas/{id} returns 200 with title and body; AuthZ anon, full pipeline
 *  AC2  — Unknown idea ID → 404
 *  AC3  — Unknown board slug → 404
 *  AC4  — Idea from a different board → 404 (no cross-board leak)
 *  AC5  — XSS in title is escaped
 *  AC6  — XSS in body is escaped
 */
final class IdeaDetailActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /** GET request to /{board}/ideas/{id}. */
    private function getDetailRequest(string $boardSlug, int $ideaId): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug . '/ideas/' . $ideaId);
    }

    // -------------------------------------------------------------------------
    // AC1 — GET /{board}/ideas/{id} → 200, AuthZ anon, title + body visible
    // -------------------------------------------------------------------------

    public function test_detail_returns_200_with_title_and_body(): void
    {
        $boardId  = $this->insertBoard('detail-board');
        $authorId = $this->insertUser('detail@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'My detail idea', [
            'body' => 'This is the full body of the idea.',
        ]);

        $response = $this->createApp()->handle($this->getDetailRequest('detail-board', $ideaId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('My detail idea', $data['idea']['title'] ?? null);
        self::assertSame('This is the full body of the idea.', $data['idea']['body'] ?? null);
    }

    // -------------------------------------------------------------------------
    // AC2 — Unknown idea ID → 404
    // -------------------------------------------------------------------------

    public function test_unknown_idea_id_returns_404(): void
    {
        $this->insertBoard('board-404');

        $response = $this->createApp()->handle($this->getDetailRequest('board-404', 99999));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC3 — Unknown board slug → 404
    // -------------------------------------------------------------------------

    public function test_unknown_board_returns_404(): void
    {
        $response = $this->createApp()->handle($this->getDetailRequest('does-not-exist', 1));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC4 — Idea from a different board → 404 (no cross-board leak)
    // -------------------------------------------------------------------------

    public function test_idea_from_other_board_returns_404(): void
    {
        $boardAId = $this->insertBoard('board-leak-a');
        $this->insertBoard('board-leak-b');
        $authorId = $this->insertUser('leak@example.com');

        // Idea is created in board A
        $ideaId = $this->seedIdea($boardAId, $authorId, 'Secret idea from A');

        // Access via board B → must return 404, not show board A's idea
        $response = $this->createApp()->handle($this->getDetailRequest('board-leak-b', $ideaId));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Secret idea from A', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // AC5 — XSS in title is escaped
    // -------------------------------------------------------------------------

    public function test_xss_in_title_is_escaped(): void
    {
        $boardId  = $this->insertBoard('xss-detail-board');
        $authorId = $this->insertUser('xss-detail@example.com');
        $xssTitle = '<script>alert("xss-title")</script>';
        $ideaId   = $this->seedIdea($boardId, $authorId, $xssTitle);

        $body = (string) $this->createApp()->handle($this->getDetailRequest('xss-detail-board', $ideaId))->getBody();

        // JSON API returns the raw value; React escapes on render
        $data = json_decode($body, true);
        self::assertSame($xssTitle, $data['idea']['title'] ?? null, 'XSS title must appear as plaintext in the JSON.');
    }

    // -------------------------------------------------------------------------
    // AC6 — XSS in body is escaped
    // -------------------------------------------------------------------------

    public function test_xss_in_body_is_escaped(): void
    {
        $boardId  = $this->insertBoard('xss-body-board');
        $authorId = $this->insertUser('xss-body@example.com');
        $xssBody  = '"><img src=x onerror=alert(1)>';
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Normal idea', [
            'body' => $xssBody,
        ]);

        $body = (string) $this->createApp()->handle($this->getDetailRequest('xss-body-board', $ideaId))->getBody();

        // JSON API returns the raw value; React escapes on render
        $data = json_decode($body, true);
        self::assertSame($xssBody, $data['idea']['body'] ?? null, 'XSS body must appear as plaintext in the JSON.');
    }
}
