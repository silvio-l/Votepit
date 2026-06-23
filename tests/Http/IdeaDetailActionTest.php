<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für GET /{board}/ideas/{id} — Idee-Detailansicht (Sprint 3, Issue 04).
 *
 * Alle Assertions laufen ausschließlich durch den HTTP-Seam (AppFactory::create +
 * IntegrationTestCase). Kein direkter Zugriff auf Repository-Interna.
 *
 * Abgedeckte ACs:
 *  AC1  — GET /{board}/ideas/{id} liefert 200 mit Titel und Body; AuthZ anon, volle Pipeline
 *  AC2  — Unbekannte Idee-ID → 404
 *  AC3  — Unbekannter Board-Slug → 404
 *  AC4  — Idee aus anderem Board → 404 (kein Cross-Board-Leak)
 *  AC5  — XSS in Titel wird escaped
 *  AC6  — XSS in Body wird escaped
 */
final class IdeaDetailActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    /** GET-Request auf /{board}/ideas/{id}. */
    private function getDetailRequest(string $boardSlug, int $ideaId): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug . '/ideas/' . $ideaId);
    }

    // -------------------------------------------------------------------------
    // AC1 — GET /{board}/ideas/{id} → 200, AuthZ anon, Titel + Body sichtbar
    // -------------------------------------------------------------------------

    public function test_detail_returns_200_with_title_and_body(): void
    {
        $boardId  = $this->insertBoard('detail-board');
        $authorId = $this->insertUser('detail@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Meine Detail-Idee', [
            'body' => 'Das ist der vollständige Body der Idee.',
        ]);

        $response = $this->createApp()->handle($this->getDetailRequest('detail-board', $ideaId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Meine Detail-Idee', $body);
        self::assertStringContainsString('Das ist der vollständige Body der Idee.', $body);
    }

    // -------------------------------------------------------------------------
    // AC2 — Unbekannte Idee-ID → 404
    // -------------------------------------------------------------------------

    public function test_unknown_idea_id_returns_404(): void
    {
        $this->insertBoard('board-404');

        $response = $this->createApp()->handle($this->getDetailRequest('board-404', 99999));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC3 — Unbekannter Board-Slug → 404
    // -------------------------------------------------------------------------

    public function test_unknown_board_returns_404(): void
    {
        $response = $this->createApp()->handle($this->getDetailRequest('does-not-exist', 1));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC4 — Idee aus anderem Board → 404 (kein Cross-Board-Leak)
    // -------------------------------------------------------------------------

    public function test_idea_from_other_board_returns_404(): void
    {
        $boardAId = $this->insertBoard('board-leak-a');
        $this->insertBoard('board-leak-b');
        $authorId = $this->insertUser('leak@example.com');

        // Idee wird in Board A angelegt
        $ideaId = $this->seedIdea($boardAId, $authorId, 'Geheime Idee aus A');

        // Zugriff über Board B → muss 404 liefern, nicht Board A's Idee zeigen
        $response = $this->createApp()->handle($this->getDetailRequest('board-leak-b', $ideaId));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Geheime Idee aus A', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // AC5 — XSS in Titel wird escaped
    // -------------------------------------------------------------------------

    public function test_xss_in_title_is_escaped(): void
    {
        $boardId  = $this->insertBoard('xss-detail-board');
        $authorId = $this->insertUser('xss-detail@example.com');
        $xssTitle = '<script>alert("xss-title")</script>';
        $ideaId   = $this->seedIdea($boardId, $authorId, $xssTitle);

        $body = (string) $this->createApp()->handle($this->getDetailRequest('xss-detail-board', $ideaId))->getBody();

        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    // -------------------------------------------------------------------------
    // AC6 — XSS in Body wird escaped
    // -------------------------------------------------------------------------

    public function test_xss_in_body_is_escaped(): void
    {
        $boardId  = $this->insertBoard('xss-body-board');
        $authorId = $this->insertUser('xss-body@example.com');
        $xssBody  = '"><img src=x onerror=alert(1)>';
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Normale Idee', [
            'body' => $xssBody,
        ]);

        $body = (string) $this->createApp()->handle($this->getDetailRequest('xss-body-board', $ideaId))->getBody();

        self::assertStringNotContainsString('<img src=x onerror', $body);
        self::assertStringContainsString('&lt;img', $body);
    }
}
