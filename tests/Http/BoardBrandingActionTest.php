<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für die Branding-Einstellseite (Issue 08, Sprint 2).
 *
 * Booten über AppFactory mit SQLite-In-Memory. Assertions prüfen ausschließlich
 * beobachtbares Verhalten: HTTP-Status, gerendertes HTML (Inline-Override) und
 * DB-Zustand (boards-Branding-Spalten).
 */
final class BoardBrandingActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Seedet ein Board; liefert dessen slug. */
    private function seedBoard(
        string $slug = 'demo',
        ?string $primary = null,
        ?string $secondary = null,
        ?string $logo = null,
    ): string {
        $this->conn->insert('boards', [
            'slug'            => $slug,
            'name'            => 'Demo Board',
            'primary_color'   => $primary,
            'secondary_color' => $secondary,
            'logo_url'        => $logo,
            'is_default'      => 1,
            'created_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $slug;
    }

    /** Seedet einen User (optional Admin); liefert dessen ID. */
    private function seedUser(string $email = 'user@example.com', bool $admin = false): int
    {
        $this->conn->insert('users', [
            'email'         => $email,
            'is_admin'      => $admin ? 1 : 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    /** GET-Request auf die Branding-Seite, optional als eingeloggter User. */
    private function getRequest(string $slug, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/boards/' . $slug . '/branding');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }

        return $request;
    }

    /**
     * POST-Request auf die Branding-Seite mit gültigem CSRF, optional eingeloggt.
     *
     * @param array<string, string> $fields
     */
    private function postRequest(string $slug, ?int $userId, array $fields, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
            $fields['_csrf']         = $csrfToken;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/branding')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    // -------------------------------------------------------------------------
    // AC5: admin-only — anon / non-admin abgewiesen, Admin erlaubt
    // -------------------------------------------------------------------------

    public function test_get_as_anon_is_rejected(): void
    {
        $slug     = $this->seedBoard();
        $response = $this->createApp()->handle($this->getRequest($slug, null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_as_non_admin_is_rejected(): void
    {
        $slug     = $this->seedBoard();
        $userId   = $this->seedUser('plain@example.com', false);
        $response = $this->createApp()->handle($this->getRequest($slug, $userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_as_admin_is_allowed(): void
    {
        $slug     = $this->seedBoard();
        $adminId  = $this->seedUser('admin@example.com', true);
        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('board_slug', $data);
    }

    public function test_post_as_non_admin_with_valid_csrf_is_rejected(): void
    {
        $slug   = $this->seedBoard();
        $userId = $this->seedUser('plain2@example.com', false);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $userId, ['primary_color' => '#123456']),
        );

        self::assertSame(403, $response->getStatusCode());

        // Branding darf NICHT geschrieben worden sein.
        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored);
    }

    // -------------------------------------------------------------------------
    // AC6: CSRF-geschützt, board-scoped, Prepared Statements
    // -------------------------------------------------------------------------

    public function test_post_without_csrf_is_rejected(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin2@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['primary_color' => '#123456'], withCsrf: false),
        );

        self::assertSame(403, $response->getStatusCode());

        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored);
    }

    public function test_admin_saves_valid_branding(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin3@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '/assets/logo.svg',
        ]));

        // 200 + JSON {"ok": true} (kein 302-Redirect; SPA navigiert selbst)
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertSame('#123456', $row['primary_color']);
        self::assertSame('#654321', $row['secondary_color']);
        self::assertSame('/assets/logo.svg', $row['logo_url']);
    }

    // -------------------------------------------------------------------------
    // AC4: ungültiges Hex wird abgewiesen → NULL (Default), kein roher Wert
    // -------------------------------------------------------------------------

    public function test_admin_save_rejects_invalid_color_to_null(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin4@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#abc;color:red',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored); // ungültig → null, kein roher Wert gespeichert
    }

    // -------------------------------------------------------------------------
    // AC2/AC3/AC4: Konsum-Seam über die HTTP-Seam (gerendertes Layout)
    // -------------------------------------------------------------------------

    public function test_branded_board_renders_inline_override(): void
    {
        $slug    = $this->seedBoard('branded', '#123456', '#654321', '/assets/logo.svg');
        $adminId = $this->seedUser('admin5@example.com', true);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(),
            true,
        );

        // JSON-API liefert sanitisierte Branding-Felder (SPA rendert den Inline-Override)
        self::assertSame('#123456', $data['primary_color'] ?? null);
        self::assertSame('/assets/logo.svg', $data['logo_url'] ?? null);
        // Kein semantischer Token darf direkt im primary_color stehen
        self::assertStringNotContainsString('--vp-vote-up', $data['primary_color'] ?? '');
    }

    public function test_unbranded_board_renders_default_theme(): void
    {
        $slug    = $this->seedBoard('plain-board');
        $adminId = $this->seedUser('admin6@example.com', true);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(),
            true,
        );

        // Kein Branding gesetzt → null-Felder (SPA zeigt Default-Theme)
        self::assertArrayHasKey('primary_color', $data);
        self::assertNull($data['primary_color']);
    }

    public function test_invalid_stored_color_falls_back_to_default(): void
    {
        // Direkt einen ungültigen Wert in die DB schreiben (Legacy/manuelle Edits).
        $slug    = $this->seedBoard('legacy', '#abc;color:red');
        $adminId = $this->seedUser('admin7@example.com', true);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(),
            true,
        );

        // Ungültiger gespeicherter Wert → API sanitisiert auf null (Default-Theme greift)
        self::assertArrayHasKey('primary_color', $data);
        self::assertNull($data['primary_color']);
    }

    public function test_unknown_board_returns_404(): void
    {
        $adminId  = $this->seedUser('admin8@example.com', true);
        $response = $this->createApp()->handle($this->getRequest('does-not-exist', $adminId));

        self::assertSame(404, $response->getStatusCode());
    }
}
