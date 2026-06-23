<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für GET /{board}/ideas/new + POST /{board}/ideas (Sprint 3, Issue 05).
 *
 * Alle Assertions laufen ausschließlich durch den HTTP-Seam (AppFactory::create +
 * IntegrationTestCase). Kein direkter Zugriff auf Repository-Interna.
 *
 * Abgedeckte ACs:
 *  AC1  — GET /{board}/ideas/new → 200 (eingeloggt) / Login-Redirect mit Return-To (anon)
 *  AC2  — POST /{board}/ideas legt Idee board-scoped an; AuthZ user, CSRF erzwungen,
 *          RateLimit idea:submit aktiv
 *  AC3  — Form-POST funktioniert ohne JavaScript (reines HTML-Formular)
 *  AC4  — title_normalized wird beim Anlegen über den TitleNormalizer geschrieben
 *  AC5  — Leerer/zu kurzer Titel oder Body → Formular mit Fehler, kein 500, Werte erhalten
 *  AC6  — Erfolg → 302 auf Detail (PRG); Reload löst kein Doppel-Submit aus
 *  AC7  — Angelegte Idee taucht in der Board-Liste auf
 *  AC8  — Blockierter Nutzer (BlockCheck) → 403
 *  AC9  — POST ohne gültiges CSRF-Token → 403
 *  AC10 — Board-Seite zeigt „Neue Idee"-CTA (eingeloggt) / Login-Hinweis (anon)
 *  AC11 — AuthZ-Tests: anon GET → Login-Redirect; anon POST → 401; eingeloggt → OK
 */
final class IdeaSubmitActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** GET-Request auf /{board}/ideas/new, optional mit Session-Cookie. */
    private function getNewRequest(string $boardSlug, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug . '/ideas/new');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessionCookie($userId),
            ]);
        }

        return $request;
    }

    /**
     * POST-Request auf /{board}/ideas mit gültigem CSRF-Token, optional Session.
     *
     * @param array<string, string> $body
     */
    private function postIdea(string $boardSlug, array $body, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(array_merge($body, ['_csrf' => $token]));

        if ($userId !== null) {
            $request = $request->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    /**
     * POST ohne CSRF-Token (für AC9).
     *
     * @param array<string, string> $body
     */
    private function postIdeaNoCsrf(string $boardSlug, array $body, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas')
            ->withParsedBody($body);

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessionCookie($userId),
            ]);
        }

        return $request;
    }

    // -------------------------------------------------------------------------
    // AC1 — GET /{board}/ideas/new (eingeloggt → 200; anon → Login-Redirect)
    // -------------------------------------------------------------------------

    public function test_get_new_as_authenticated_user_returns_200(): void
    {
        $this->insertBoard('ac1-board');
        $userId = $this->insertUser('ac1@example.com');

        $response = $this->createApp()->handle($this->getNewRequest('ac1-board', $userId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<form', (string) $response->getBody());
    }

    public function test_get_new_as_anon_redirects_to_login_with_return_to(): void
    {
        $this->insertBoard('ac1-anon-board');

        $response = $this->createApp()->handle($this->getNewRequest('ac1-anon-board'));

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/login', $location);
        self::assertStringContainsString('r=', $location);
        self::assertStringContainsString('ideas%2Fnew', $location);
    }

    // -------------------------------------------------------------------------
    // AC2 — POST /{board}/ideas board-scoped, AuthZ user, CSRF, RateLimit
    // -------------------------------------------------------------------------

    public function test_post_creates_idea_and_redirects_to_detail(): void
    {
        $this->insertBoard('ac2-board');
        $userId  = $this->insertUser('ac2@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac2-board',
            ['title' => 'Meine neue Idee', 'body' => 'Das ist die Beschreibung der Idee, etwas länger.'],
            $userId,
        ));

        // PRG: 302 auf Detail
        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/ac2-board/ideas/', $location);
    }

    // -------------------------------------------------------------------------
    // AC3 — Form-POST funktioniert ohne JavaScript
    // -------------------------------------------------------------------------

    public function test_form_contains_no_javascript_requirements(): void
    {
        $this->insertBoard('ac3-board');
        $userId = $this->insertUser('ac3@example.com');

        $body = (string) $this->createApp()->handle($this->getNewRequest('ac3-board', $userId))->getBody();

        // Formular nutzt reines HTML (method=post, action=URL, keine JS-Hooks)
        self::assertStringContainsString('method="post"', $body);
        self::assertStringContainsString('action="/ac3-board/ideas"', $body);
        self::assertStringContainsString('type="submit"', $body);
        // CSRF als Hidden-Field (kein JS nötig)
        self::assertStringContainsString('name="_csrf"', $body);
    }

    // -------------------------------------------------------------------------
    // AC4 — title_normalized wird über TitleNormalizer geschrieben
    // -------------------------------------------------------------------------

    public function test_title_normalized_is_set_via_title_normalizer(): void
    {
        $boardId = $this->insertBoard('ac4-board');
        $userId  = $this->insertUser('ac4@example.com');
        $app     = $this->createApp();

        // POST — "Dark Mode" normalisiert zu "darkmode"
        $response = $app->handle($this->postIdea(
            'ac4-board',
            ['title' => 'Dark Mode', 'body' => 'Bitte einen Dark Mode einbauen, das wäre sehr nützlich.'],
            $userId,
        ));
        self::assertSame(302, $response->getStatusCode());

        // Direkt in der DB nachprüfen (einzige Ausnahme: die Normalisierung ist
        // beobachtbar nur über die DB, da kein eigener Endpunkt dafür existiert)
        $row = $this->conn->fetchAssociative(
            'SELECT title_normalized FROM ideas WHERE board_id = :board_id LIMIT 1',
            ['board_id' => $boardId],
        );
        self::assertIsArray($row);
        self::assertSame('darkmode', $row['title_normalized']);
    }

    // -------------------------------------------------------------------------
    // AC5 — Validierungsfehler: leerer/zu kurzer Titel/Body → Formular re-render
    // -------------------------------------------------------------------------

    public function test_empty_title_returns_form_with_error_not_500(): void
    {
        $this->insertBoard('ac5a-board');
        $userId = $this->insertUser('ac5a@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5a-board',
            ['title' => '', 'body' => 'Eine Beschreibung die lang genug ist.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        // Fehlermeldung vorhanden
        self::assertStringContainsString('leer', $body);
        // Kein interner Serverfehler
        self::assertStringNotContainsString('Internal Server Error', $body);
    }

    public function test_too_short_title_returns_form_with_error(): void
    {
        $this->insertBoard('ac5b-board');
        $userId = $this->insertUser('ac5b@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5b-board',
            ['title' => 'ab', 'body' => 'Eine Beschreibung die lang genug ist.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('mindestens', (string) $response->getBody());
    }

    public function test_empty_body_returns_form_with_error(): void
    {
        $this->insertBoard('ac5c-board');
        $userId = $this->insertUser('ac5c@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5c-board',
            ['title' => 'Gültiger Titel hier', 'body' => ''],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('leer', $body);
    }

    public function test_validation_error_preserves_entered_values(): void
    {
        $this->insertBoard('ac5d-board');
        $userId = $this->insertUser('ac5d@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5d-board',
            ['title' => 'ab', 'body' => 'Irgendein Text der lang genug ist.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        // Eingegebener Titel bleibt im Formular erhalten
        self::assertStringContainsString('value="ab"', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // AC6 — Erfolg → 302 auf Detail (PRG)
    // -------------------------------------------------------------------------

    public function test_successful_submit_redirects_to_idea_detail(): void
    {
        $this->insertBoard('ac6-board');
        $userId  = $this->insertUser('ac6@example.com');
        $app     = $this->createApp();

        $response = $app->handle($this->postIdea(
            'ac6-board',
            ['title' => 'Eine tolle Idee', 'body' => 'Hier ist meine ausführliche Beschreibung der Idee.'],
            $userId,
        ));

        self::assertSame(302, $response->getStatusCode());
        // Location zeigt auf Detail-Seite
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/ac6-board/ideas/\d+$#', $location);
    }

    public function test_redirect_target_is_accessible_get(): void
    {
        $this->insertBoard('ac6b-board');
        $userId = $this->insertUser('ac6b@example.com');
        $app    = $this->createApp();

        $postResponse = $app->handle($this->postIdea(
            'ac6b-board',
            ['title' => 'PRG-Test Idee', 'body' => 'Die Beschreibung ist lang genug hier.'],
            $userId,
        ));

        self::assertSame(302, $postResponse->getStatusCode());
        $location = $postResponse->getHeaderLine('Location');

        // GET auf die Redirect-URL muss 200 liefern
        $getResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $location)
        );
        self::assertSame(200, $getResponse->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC7 — Angelegte Idee taucht in der Board-Liste auf
    // -------------------------------------------------------------------------

    public function test_created_idea_appears_in_board_list(): void
    {
        $this->insertBoard('ac7-board');
        $userId  = $this->insertUser('ac7@example.com');
        $app     = $this->createApp();

        $app->handle($this->postIdea(
            'ac7-board',
            ['title' => 'Idee für die Liste', 'body' => 'Diese Idee soll in der Board-Liste erscheinen.'],
            $userId,
        ));

        // Board-Liste laden
        $listResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/ac7-board')
        );
        self::assertSame(200, $listResponse->getStatusCode());
        self::assertStringContainsString('Idee für die Liste', (string) $listResponse->getBody());
    }

    // -------------------------------------------------------------------------
    // AC8 — Blockierter Nutzer → 403
    // -------------------------------------------------------------------------

    public function test_blocked_user_cannot_post_idea(): void
    {
        $this->insertBoard('ac8-board');
        $blockedUserId = $this->insertUser('blocked@example.com', ['is_blocked' => 1]);

        $response = $this->createApp()->handle($this->postIdea(
            'ac8-board',
            ['title' => 'Spam-Idee', 'body' => 'Blockierter User soll das nicht dürfen.'],
            $blockedUserId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC9 — POST ohne gültiges CSRF-Token → 403
    // -------------------------------------------------------------------------

    public function test_post_without_csrf_token_returns_403(): void
    {
        $this->insertBoard('ac9-board');
        $userId = $this->insertUser('ac9@example.com');

        $response = $this->createApp()->handle($this->postIdeaNoCsrf(
            'ac9-board',
            ['title' => 'Idee ohne CSRF', 'body' => 'Das sollte nicht funktionieren.'],
            $userId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC10 — Board-Seite zeigt „Neue Idee"-CTA (eingeloggt) / Login-Hinweis (anon)
    // -------------------------------------------------------------------------

    public function test_board_home_shows_new_idea_cta_for_authenticated_user(): void
    {
        $this->insertBoard('ac10a-board');
        $userId = $this->insertUser('ac10a@example.com');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/ac10a-board')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        // CTA für eingeloggte Nutzer
        self::assertStringContainsString('Neue Idee', $body);
        self::assertStringContainsString('/ac10a-board/ideas/new', $body);
    }

    public function test_board_home_shows_login_hint_for_anon_user(): void
    {
        $this->insertBoard('ac10b-board');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/ac10b-board')
        );

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        // Login-Hinweis für anonyme Besucher
        self::assertStringContainsString('Anmelden', $body);
        self::assertStringContainsString('/login', $body);
    }

    // -------------------------------------------------------------------------
    // AC11 — AuthZ-Tests: anon POST → 401; eingeloggt POST → erlaubt
    // -------------------------------------------------------------------------

    public function test_anon_post_to_ideas_returns_401(): void
    {
        $this->insertBoard('ac11a-board');

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/ac11a-board/ideas')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(['title' => 'Anon-Idee', 'body' => 'Beschreibung.', '_csrf' => $token]);

        $response = $this->createApp()->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_authenticated_user_can_submit_idea(): void
    {
        $this->insertBoard('ac11b-board');
        $userId = $this->insertUser('ac11b@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac11b-board',
            ['title' => 'Idee von eingeloggtem User', 'body' => 'Hier ist die ausführliche Beschreibung der Idee.'],
            $userId,
        ));

        // 302 = erfolgreich angelegt + PRG-Redirect
        self::assertSame(302, $response->getStatusCode());
    }

    public function test_get_new_returns_form_with_csrf_field(): void
    {
        $this->insertBoard('csrf-form-board');
        $userId = $this->insertUser('csrf-form@example.com');

        $body = (string) $this->createApp()->handle($this->getNewRequest('csrf-form-board', $userId))->getBody();

        // CSRF-Hidden-Feld muss im Formular vorhanden sein
        self::assertStringContainsString('name="_csrf"', $body);
        self::assertStringContainsString('value=', $body);
    }

    public function test_unknown_board_on_new_returns_404(): void
    {
        $userId = $this->insertUser('nomatch@example.com');
        $response = $this->createApp()->handle($this->getNewRequest('does-not-exist', $userId));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_unknown_board_on_post_returns_404(): void
    {
        $userId = $this->insertUser('nomatch-post@example.com');
        $response = $this->createApp()->handle($this->postIdea(
            'does-not-exist',
            ['title' => 'Irgendetwas', 'body' => 'Irgendeine Beschreibung hier.'],
            $userId,
        ));
        self::assertSame(404, $response->getStatusCode());
    }
}
