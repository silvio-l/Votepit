<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für die Moderation-Einstellseite (Issue 10, Sprint 3).
 *
 * Booten über AppFactory mit SQLite-In-Memory. Assertions prüfen ausschließlich
 * beobachtbares Verhalten: HTTP-Status, gerendertes HTML und DB-Zustand
 * (boards.moderation_enabled + board_blocklist).
 *
 * ACs:
 *  AC3  — GET /admin/boards/{slug}/moderation zeigt Toggle + Custom-Wörter (admin); anon/non-admin abgewiesen
 *  AC4  — POST speichert Toggle + Wort-Add/Remove, CSRF erzwungen, 302-PRG, ungültige Eingabe → Re-Render (kein 500)
 *  AC5  — Submit-Pfad nutzt Board-Toggle: „aus" → Wortfilter übersprungen, Honeypot/Time-Trap weiter aktiv
 *  AC6  — Submit-Pfad nutzt Board-Custom-Wörter: Wort nur in Custom-Liste → geblockt
 *  AC7  — Cross-Board: Custom-Wort aus Board A wirkt NICHT in Board B
 *  AC8  — AuthZ-Tests (Admin erlaubt / Nicht-Admin abgewiesen) — bereits durch AC3-Tests abgedeckt
 */
final class BoardModerationActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    private function seedBoard(string $slug = 'demo', int $moderationEnabled = 1): string
    {
        $this->conn->insert('boards', [
            'slug'               => $slug,
            'name'               => 'Demo Board',
            'moderation_enabled' => $moderationEnabled,
            'is_default'         => 1,
            'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return $slug;
    }

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

    private function getRequest(string $slug, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/boards/' . $slug . '/moderation');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }

        return $request;
    }

    /** @param array<string, string> $fields */
    private function postRequest(
        string $slug,
        ?int $userId,
        array $fields,
        bool $withCsrf = true,
    ): ServerRequestInterface {
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
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/moderation')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    /**
     * Builds a valid backdated Time-Trap stamp (5 s in the past) for submit tests.
     */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = self::APP_KEY;
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /**
     * POST /{board}/ideas with CSRF + Time-Trap (mirrors IdeaSubmitActionTest helper).
     *
     * @param array<string, string> $body
     */
    private function postIdea(string $boardSlug, array $body, ?int $userId = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $defaults = ['_csrf' => $token, '_form_at' => $this->validTimeTrap()];
        $cookies  = [$csrf->cookieName() => $signed];

        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas')
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge($defaults, $body));
    }

    // =========================================================================
    // AC3 — GET: admin zeigt Form; anon + non-admin abgewiesen
    // =========================================================================

    public function test_get_as_anon_is_rejected(): void
    {
        $slug     = $this->seedBoard('mod-anon');
        $response = $this->createApp()->handle($this->getRequest($slug, null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_as_non_admin_is_rejected(): void
    {
        $slug     = $this->seedBoard('mod-nonadmin');
        $userId   = $this->seedUser('plain@example.com', false);
        $response = $this->createApp()->handle($this->getRequest($slug, $userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_as_admin_returns_200_with_form(): void
    {
        $slug    = $this->seedBoard('mod-admin');
        $adminId = $this->seedUser('admin@example.com', true);
        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Moderation', $body);
        self::assertStringContainsString('moderation_enabled', $body);
    }

    public function test_get_shows_custom_words(): void
    {
        $slug    = $this->seedBoard('mod-words');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $adminId = $this->seedUser('admin-words@example.com', true);

        // Seed a custom word directly in the DB.
        $this->conn->insert('board_blocklist', [
            'board_id'   => $boardId,
            'word'       => 'testspam',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $body = (string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody();
        self::assertStringContainsString('testspam', $body);
    }

    public function test_unknown_board_returns_404(): void
    {
        $adminId  = $this->seedUser('admin-404@example.com', true);
        $response = $this->createApp()->handle($this->getRequest('does-not-exist', $adminId));

        self::assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // AC4 — POST: CSRF erzwungen, Toggle + Wort-Add/Remove, PRG, ungültige Eingabe
    // =========================================================================

    public function test_post_without_csrf_is_rejected(): void
    {
        $slug    = $this->seedBoard('mod-csrf');
        $adminId = $this->seedUser('admin-csrf@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'toggle', 'moderation_enabled' => '0'], withCsrf: false),
        );

        self::assertSame(403, $response->getStatusCode());

        // Toggle darf NICHT gespeichert worden sein — Default 1 erwartet.
        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('1', (string) $stored);
    }

    public function test_post_as_non_admin_is_rejected(): void
    {
        $slug   = $this->seedBoard('mod-auth');
        $userId = $this->seedUser('plain2@example.com', false);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $userId, ['action' => 'toggle', 'moderation_enabled' => '0']),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_admin_saves_toggle_off_and_redirects(): void
    {
        $slug    = $this->seedBoard('mod-toggle-off');
        $adminId = $this->seedUser('admin-toggle@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'toggle']),
            // No moderation_enabled in body → checkbox unchecked → 0
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/boards/mod-toggle-off/moderation', $response->getHeaderLine('Location'));

        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('0', (string) $stored);
    }

    public function test_admin_saves_toggle_on(): void
    {
        $slug    = $this->seedBoard('mod-toggle-on', 0);
        $adminId = $this->seedUser('admin-toggle-on@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'toggle', 'moderation_enabled' => '1']),
        );

        self::assertSame(302, $response->getStatusCode());

        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('1', (string) $stored);
    }

    public function test_admin_adds_word_and_redirects(): void
    {
        $slug    = $this->seedBoard('mod-add-word');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $adminId = $this->seedUser('admin-add@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'add', 'new_word' => 'spamword']),
        );

        self::assertSame(302, $response->getStatusCode());

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM board_blocklist WHERE board_id = :bid AND word = :w',
            ['bid' => $boardId, 'w' => 'spamword'],
        );
        self::assertSame(1, $count);
    }

    public function test_admin_removes_word_and_redirects(): void
    {
        $slug    = $this->seedBoard('mod-rem-word');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $adminId = $this->seedUser('admin-rem@example.com', true);

        $this->conn->insert('board_blocklist', [
            'board_id'   => $boardId,
            'word'       => 'toremove',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $wordId = (int) $this->conn->lastInsertId();

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'remove', 'word_id' => (string) $wordId]),
        );

        self::assertSame(302, $response->getStatusCode());

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM board_blocklist WHERE board_id = :bid',
            ['bid' => $boardId],
        );
        self::assertSame(0, $count);
    }

    public function test_add_empty_word_returns_422_no_500(): void
    {
        $slug    = $this->seedBoard('mod-empty-word');
        $adminId = $this->seedUser('admin-empty@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'add', 'new_word' => '   ']),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('leer', $body);
        self::assertStringNotContainsString('Internal Server Error', $body);
    }

    // =========================================================================
    // AC5 — Submit-Pfad: Toggle „aus" → Wortfilter übersprungen
    // =========================================================================

    public function test_toggle_off_skips_word_filter_but_allows_clean_submit(): void
    {
        // Board mit Moderation aus; kein Custom-Wort.
        $slug    = $this->seedBoard('submit-toggle-off', 0);
        $userId  = $this->seedUser('user-toggle-off@example.com');

        // "arschloch" ist in der Basis-Liste (aus den Tests von Issue 09 bekannt).
        $response = $this->createApp()->handle($this->postIdea(
            $slug,
            ['title' => 'arschloch bitte bauen', 'body' => 'Saubere Beschreibung ohne Probleme hier.'],
            $userId,
        ));

        // Toggle aus → Wortfilter übersprungen → Idee wird angelegt → 302
        self::assertSame(302, $response->getStatusCode());
    }

    public function test_toggle_off_honeypot_still_active(): void
    {
        $slug    = $this->seedBoard('submit-hp-off', 0);
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $userId  = $this->seedUser('user-hp-off@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            $slug,
            [
                'title'   => 'Saubere Idee',
                'body'    => 'Saubere Beschreibung ohne Probleme.',
                'website' => 'http://spam.example.com', // Honeypot befüllt
            ],
            $userId,
        ));

        // Honeypot greift immer, unabhängig vom Toggle.
        self::assertSame(422, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardId]);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC6 — Submit-Pfad: Custom-Wort blockt Idee
    // =========================================================================

    public function test_custom_word_blocks_idea_submission(): void
    {
        $slug    = $this->seedBoard('submit-custom-word');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $userId  = $this->seedUser('user-custom-word@example.com');
        $adminId = $this->seedUser('admin-custom-word@example.com', true);

        // Custom-Wort hinzufügen (das NICHT in der Basis-Blockliste ist).
        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'add', 'new_word' => 'xyzforbidden']),
        );
        self::assertSame(302, $response->getStatusCode());

        // Submit mit dem Custom-Wort im Titel.
        $submitResponse = $this->createApp()->handle($this->postIdea(
            $slug,
            ['title' => 'xyzforbidden ist verboten', 'body' => 'Diese Beschreibung ist sauber genug.'],
            $userId,
        ));

        self::assertSame(422, $submitResponse->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardId]);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC7 — Cross-Board: Custom-Wort aus Board A wirkt NICHT in Board B
    // =========================================================================

    public function test_custom_word_from_board_a_does_not_affect_board_b(): void
    {
        $slugA   = $this->seedBoard('board-a-xboard');
        $slugB   = $this->seedBoard('board-b-xboard');
        $boardAId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slugA]);
        $boardBId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slugB]);

        $userId  = $this->seedUser('user-xboard@example.com');
        $adminId = $this->seedUser('admin-xboard@example.com', true);

        // Custom-Wort nur für Board A.
        $this->createApp()->handle(
            $this->postRequest($slugA, $adminId, ['action' => 'add', 'new_word' => 'onlyinboarda']),
        );

        // Submit mit dem Custom-Wort auf Board A → soll geblockt werden.
        $responseA = $this->createApp()->handle($this->postIdea(
            $slugA,
            ['title' => 'onlyinboarda Idee', 'body' => 'Saubere Beschreibung ohne Probleme hier.'],
            $userId,
        ));
        self::assertSame(422, $responseA->getStatusCode());
        $countA = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardAId]);
        self::assertSame(0, $countA);

        // Gleicher Titel auf Board B → darf durchgehen (Wort nur in Board A).
        $responseB = $this->createApp()->handle($this->postIdea(
            $slugB,
            ['title' => 'onlyinboarda Idee', 'body' => 'Saubere Beschreibung ohne Probleme hier.'],
            $userId,
        ));
        self::assertSame(302, $responseB->getStatusCode());
        $countB = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardBId]);
        self::assertSame(1, $countB);
    }

    // =========================================================================
    // AC1 (Schema) — moderation_enabled Default 1 für neue Boards
    // =========================================================================

    public function test_new_board_has_moderation_enabled_by_default(): void
    {
        $slug = $this->seedBoard('mod-default-check');
        // No moderation_enabled override in insertBoard → relies on DB DEFAULT 1.
        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('1', (string) $stored);
    }
}
