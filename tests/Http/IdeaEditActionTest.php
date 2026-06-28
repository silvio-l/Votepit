<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für GET /{board}/ideas/{id}/edit + POST /{board}/ideas/{id}
 * (Sprint 3, Issue 06 — Eigene Idee editieren / Row-Level-Ownership).
 *
 * Alle Assertions laufen ausschließlich durch den HTTP-Seam.
 *
 * Abgedeckte ACs:
 *  AC1  — GET /edit zeigt das vorbefüllte Edit-Formular nur dem Autor (200)
 *  AC2  — POST aktualisiert die eigene Idee; AuthZ user + Ownership, CSRF erzwungen
 *  AC3  — title_normalized wird bei Update über den TitleNormalizer re-normalisiert
 *  AC4  — Fremder Non-Admin → 403; Idee nicht im Board → 404; anonym → Login-Redirect
 *  AC5  — Blockierter Nutzer → abgewiesen (403)
 *  AC6  — Ungültige Eingabe → Formular mit Fehler re-rendert, kein 500, Werte erhalten
 *  AC7  — updateOwn bindet author_id/board_id als Parameter (Prepared-Statement)
 *  AC8  — Edit-Pfad: Profanität/Honeypot/Time-Trap → 422-Re-Render, kein Update
 *  AC9  — AuthZ-/Ownership-Tests: Owner erlaubt / fremder User 403 / anon Redirect
 */
final class IdeaEditActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * Gültiger Time-Trap-Stamp (5 s backdated — über MIN_SECONDS=3).
     */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /**
     * Ungültiger Time-Trap-Stamp (aktueller Zeitstempel → zu schnell).
     */
    private function tooFastTimeTrap(): string
    {
        $ts  = (string) time();
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /** GET-Request auf /{board}/ideas/{id}/edit, optional mit Session-Cookie. */
    private function getEditRequest(string $boardSlug, int $ideaId, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug . '/ideas/' . $ideaId . '/edit');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessionCookie($userId),
            ]);
        }

        return $request;
    }

    /**
     * POST-Request auf /{board}/ideas/{id} mit gültigem CSRF-Token + Time-Trap.
     *
     * @param array<string, string> $body
     */
    private function postEdit(string $boardSlug, int $ideaId, array $body, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $defaults = ['_csrf' => $token, '_form_at' => $this->validTimeTrap()];
        $request  = (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas/' . $ideaId)
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(array_merge($defaults, $body));

        if ($userId !== null) {
            $request = $request->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ]);
        }

        return $request;
    }

    /**
     * POST ohne CSRF-Token (für CSRF-Test).
     *
     * @param array<string, string> $body
     */
    private function postEditNoCsrf(string $boardSlug, int $ideaId, array $body, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas/' . $ideaId)
            ->withParsedBody($body);

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessionCookie($userId),
            ]);
        }

        return $request;
    }

    // -------------------------------------------------------------------------
    // AC1 — GET /edit zeigt das vorbefüllte Edit-Formular nur dem Autor (200)
    // -------------------------------------------------------------------------

    public function test_get_edit_as_author_returns_200_with_prefilled_form(): void
    {
        $boardId = $this->insertBoard('edit-ac1-board');
        $userId  = $this->insertUser('edit-ac1@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Original Titel');

        $response = $this->createApp()->handle($this->getEditRequest('edit-ac1-board', $ideaId, $userId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        // Vorbefüllter Titel im JSON
        self::assertSame('Original Titel', $data['idea']['title'] ?? null);
    }

    public function test_get_edit_form_has_csrf_field(): void
    {
        $boardId = $this->insertBoard('edit-csrf-board');
        $userId  = $this->insertUser('edit-csrf@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        // CSRF-Token wird via /api/bootstrap bereitgestellt; GET /edit liefert nur Idee-Daten
        $response = $this->createApp()->handle($this->getEditRequest('edit-csrf-board', $ideaId, $userId));
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_get_edit_form_has_honeypot_field_hidden(): void
    {
        $boardId = $this->insertBoard('edit-hp-vis-board');
        $userId  = $this->insertUser('edit-hp-vis@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        // SPA rendert das Formular inkl. Honeypot; GET /edit liefert JSON-Daten
        $response = $this->createApp()->handle($this->getEditRequest('edit-hp-vis-board', $ideaId, $userId));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_get_edit_unknown_idea_returns_404(): void
    {
        $this->insertBoard('edit-404-board');
        $userId = $this->insertUser('edit-404@example.com');

        $response = $this->createApp()->handle($this->getEditRequest('edit-404-board', 9999, $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_get_edit_idea_from_other_board_returns_404(): void
    {
        $boardId1 = $this->insertBoard('edit-b1-board');
        $this->insertBoard('edit-b2-board');
        $userId   = $this->insertUser('edit-cross@example.com');
        $ideaId   = $this->seedIdea($boardId1, $userId);

        // Idee gehört zu Board1, aber wir fragen auf Board2 — board-scoped → 404
        $response = $this->createApp()->handle($this->getEditRequest('edit-b2-board', $ideaId, $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2 — POST aktualisiert die eigene Idee; AuthZ user + Ownership, CSRF
    // -------------------------------------------------------------------------

    public function test_post_edit_updates_own_idea_and_redirects(): void
    {
        $boardId = $this->insertBoard('edit-update-board');
        $userId  = $this->insertUser('edit-update@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Alter Titel');

        $response = $this->createApp()->handle($this->postEdit(
            'edit-update-board',
            $ideaId,
            ['title' => 'Neuer Titel', 'body' => 'Neue Beschreibung, die lang genug ist hier.'],
            $userId,
        ));

        // 200 + JSON ok:true (kein 302-Redirect; SPA navigiert selbst)
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
    }

    public function test_post_edit_persists_updated_title_in_db(): void
    {
        $boardId = $this->insertBoard('edit-persist-board');
        $userId  = $this->insertUser('edit-persist@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Alter Titel');

        $this->createApp()->handle($this->postEdit(
            'edit-persist-board',
            $ideaId,
            ['title' => 'Geänderter Titel', 'body' => 'Neue Beschreibung, die lang genug ist hier.'],
            $userId,
        ));

        $row = $this->conn->fetchAssociative(
            'SELECT title FROM ideas WHERE id = :id AND board_id = :board_id',
            ['id' => $ideaId, 'board_id' => $boardId],
        );
        self::assertIsArray($row);
        self::assertSame('Geänderter Titel', $row['title']);
    }

    public function test_post_edit_without_csrf_returns_403(): void
    {
        $boardId = $this->insertBoard('edit-nocsrf-board');
        $userId  = $this->insertUser('edit-nocsrf@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEditNoCsrf(
            'edit-nocsrf-board',
            $ideaId,
            ['title' => 'Titel', 'body' => 'Beschreibung.'],
            $userId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC3 — title_normalized re-normalisiert über TitleNormalizer
    // -------------------------------------------------------------------------

    public function test_post_edit_renormalizes_title_on_update(): void
    {
        $boardId = $this->insertBoard('edit-norm-board');
        $userId  = $this->insertUser('edit-norm@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Alter Titel');

        $this->createApp()->handle($this->postEdit(
            'edit-norm-board',
            $ideaId,
            ['title' => 'Dark Mode', 'body' => 'Neue Beschreibung, die lang genug ist hier.'],
            $userId,
        ));

        $row = $this->conn->fetchAssociative(
            'SELECT title_normalized FROM ideas WHERE id = :id AND board_id = :board_id',
            ['id' => $ideaId, 'board_id' => $boardId],
        );
        self::assertIsArray($row);
        self::assertSame('darkmode', $row['title_normalized']);
    }

    // -------------------------------------------------------------------------
    // AC4 — Fremder Non-Admin → 403; Idee nicht im Board → 404; anonym → Redirect
    // -------------------------------------------------------------------------

    public function test_get_edit_by_other_user_returns_403(): void
    {
        $boardId    = $this->insertBoard('edit-403-board');
        $authorId   = $this->insertUser('author@example.com');
        $foreignId  = $this->insertUser('foreign@example.com');
        $ideaId     = $this->seedIdea($boardId, $authorId);

        $response = $this->createApp()->handle($this->getEditRequest('edit-403-board', $ideaId, $foreignId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_post_edit_by_other_user_returns_403(): void
    {
        $boardId   = $this->insertBoard('edit-post403-board');
        $authorId  = $this->insertUser('post-author@example.com');
        $foreignId = $this->insertUser('post-foreign@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-post403-board',
            $ideaId,
            ['title' => 'Fremder Versuch', 'body' => 'Das darf nicht gespeichert werden hier.'],
            $foreignId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_edit_anon_redirects_to_login(): void
    {
        $boardId = $this->insertBoard('edit-anon-board');
        $userId  = $this->insertUser('anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        // Kein User-Cookie → Action gibt 401 zurück (SPA leitet zum Login weiter)
        $response = $this->createApp()->handle($this->getEditRequest('edit-anon-board', $ideaId));

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('unauthenticated', $data['error']['key'] ?? null);
    }

    public function test_post_edit_anon_returns_401(): void
    {
        $boardId = $this->insertBoard('edit-anon-post-board');
        $userId  = $this->insertUser('anon-post-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/edit-anon-post-board/ideas/' . $ideaId)
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(['_csrf' => $token, '_form_at' => $this->validTimeTrap(), 'title' => 'Titel', 'body' => 'Beschreibung.']);

        $response = $this->createApp()->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_edit_nonexistent_idea_returns_404(): void
    {
        $this->insertBoard('edit-ne-board');
        $userId = $this->insertUser('ne@example.com');

        $response = $this->createApp()->handle($this->getEditRequest('edit-ne-board', 99999, $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5 — Blockierter Nutzer → abgewiesen (403) auf mutierenden Verb
    // -------------------------------------------------------------------------

    public function test_blocked_user_cannot_post_edit(): void
    {
        $boardId   = $this->insertBoard('edit-blocked-post-board');
        $blockedId = $this->insertUser('blocked-post-edit@example.com', ['is_blocked' => 1]);
        $ideaId    = $this->seedIdea($boardId, $blockedId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-blocked-post-board',
            $ideaId,
            ['title' => 'Spam', 'body' => 'Blockierter User soll das nicht dürfen hier.'],
            $blockedId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC6 — Ungültige Eingabe → Formular mit Fehler re-rendert, kein 500
    // -------------------------------------------------------------------------

    public function test_empty_title_on_edit_returns_422_with_error(): void
    {
        $boardId = $this->insertBoard('edit-val-board');
        $userId  = $this->insertUser('edit-val@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-val-board',
            $ideaId,
            ['title' => '', 'body' => 'Gültige Beschreibung, die lang genug ist hier.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('leer', $data['error']['fields']['title'] ?? '');
        self::assertStringNotContainsString('Internal Server Error', (string) $response->getBody());
    }

    public function test_too_short_title_on_edit_returns_422(): void
    {
        $boardId = $this->insertBoard('edit-short-board');
        $userId  = $this->insertUser('edit-short@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-short-board',
            $ideaId,
            ['title' => 'ab', 'body' => 'Gültige Beschreibung, die lang genug ist hier.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('mindestens', $data['error']['fields']['title'] ?? '');
    }

    public function test_validation_error_preserves_entered_values_on_edit(): void
    {
        $boardId = $this->insertBoard('edit-preserve-board');
        $userId  = $this->insertUser('edit-preserve@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-preserve-board',
            $ideaId,
            ['title' => 'ab', 'body' => 'Gültige Beschreibung, die lang genug ist hier.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        // Eingegebener (fehlerhafter) Wert bleibt im JSON-Fehler erhalten (SPA befüllt das Formular)
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('ab', $data['error']['values']['title'] ?? null);
    }

    public function test_validation_error_does_not_modify_db(): void
    {
        $boardId = $this->insertBoard('edit-nodb-board');
        $userId  = $this->insertUser('edit-nodb@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Unveränderter Titel');

        $this->createApp()->handle($this->postEdit(
            'edit-nodb-board',
            $ideaId,
            ['title' => '', 'body' => 'Beschreibung.'],
            $userId,
        ));

        $row = $this->conn->fetchAssociative(
            'SELECT title FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row);
        // Titel darf sich nicht geändert haben
        self::assertSame('Unveränderter Titel', $row['title']);
    }

    // -------------------------------------------------------------------------
    // AC7 — updateOwn bindet author_id/board_id als Parameter (Prepared-Statement)
    // -------------------------------------------------------------------------

    public function test_post_edit_does_not_update_other_authors_idea_with_same_board(): void
    {
        // Szenario: zwei User, gleiche Board — nur der Autor darf updaten
        $boardId  = $this->insertBoard('edit-stmt-board');
        $authorId = $this->insertUser('stmt-author@example.com');
        $otherId  = $this->insertUser('stmt-other@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Original');

        // Anderer User versucht zu editieren → 403, kein DB-Update
        $response = $this->createApp()->handle($this->postEdit(
            'edit-stmt-board',
            $ideaId,
            ['title' => 'Gekapert', 'body' => 'Das sollte nicht in die DB kommen hier.'],
            $otherId,
        ));

        self::assertSame(403, $response->getStatusCode());

        $row = $this->conn->fetchAssociative(
            'SELECT title FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row);
        self::assertSame('Original', $row['title']);
    }

    // -------------------------------------------------------------------------
    // AC8 — Moderation + Bot-Abwehr (gleicher Vertrag wie Submit)
    // -------------------------------------------------------------------------

    public function test_profanity_in_edit_returns_422_no_db_update(): void
    {
        $boardId = $this->insertBoard('edit-mod-board');
        $userId  = $this->insertUser('edit-mod@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Sauberer Originaltitel');

        $response = $this->createApp()->handle($this->postEdit(
            'edit-mod-board',
            $ideaId,
            ['title' => 'arschloch bitte bauen', 'body' => 'Saubere Beschreibung ohne Probleme hier.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        // DB unverändert
        $row = $this->conn->fetchAssociative('SELECT title FROM ideas WHERE id = ?', [$ideaId]);
        self::assertIsArray($row);
        self::assertSame('Sauberer Originaltitel', $row['title']);
        // Neutrale Meldung (via JSON-Decode, da Body unicode-escaped ist)
        $errData = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('unzulässige Begriffe', $errData['error']['message'] ?? '');
    }

    public function test_moderation_hit_on_edit_is_logged_masked(): void
    {
        $boardId = $this->insertBoard('edit-log-board');
        $userId  = $this->insertUser('edit-log@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->createApp()->handle($this->postEdit(
            'edit-log-board',
            $ideaId,
            ['title' => 'arschloch ist hier', 'body' => 'Saubere Beschreibung ohne Probleme hier.'],
            $userId,
        ));

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.moderation_blocked', $log);
        self::assertStringNotContainsString('arschloch', $log);
    }

    public function test_honeypot_filled_on_edit_returns_422_no_db_update(): void
    {
        $boardId = $this->insertBoard('edit-hp-board');
        $userId  = $this->insertUser('edit-hp@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Originaltext');

        $response = $this->createApp()->handle($this->postEdit(
            'edit-hp-board',
            $ideaId,
            ['title' => 'Sauber', 'body' => 'Saubere Beschreibung ohne Probleme hier.', 'website' => 'http://spam.example.com'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT title FROM ideas WHERE id = ?', [$ideaId]);
        self::assertIsArray($row);
        self::assertSame('Originaltext', $row['title']);
    }

    public function test_time_trap_too_fast_on_edit_returns_422_no_db_update(): void
    {
        $boardId = $this->insertBoard('edit-tt-board');
        $userId  = $this->insertUser('edit-tt@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Unveränderter Titel');

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/edit-tt-board/ideas/' . $ideaId)
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody([
                '_csrf'    => $token,
                '_form_at' => $this->tooFastTimeTrap(),
                'title'    => 'Sauberer Titel',
                'body'     => 'Saubere Beschreibung ohne Probleme hier.',
            ]);

        $response = $this->createApp()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT title FROM ideas WHERE id = ?', [$ideaId]);
        self::assertIsArray($row);
        self::assertSame('Unveränderter Titel', $row['title']);
    }

    public function test_clean_edit_succeeds_with_valid_timing(): void
    {
        $boardId = $this->insertBoard('edit-clean-board');
        $userId  = $this->insertUser('edit-clean@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-clean-board',
            $ideaId,
            ['title' => 'Sauber Aktualisiert', 'body' => 'Saubere Beschreibung ohne Probleme hier.'],
            $userId,
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_error_messages_contain_no_security_marketing(): void
    {
        $boardId = $this->insertBoard('edit-secmkt-board');
        $userId  = $this->insertUser('edit-secmkt@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-secmkt-board',
            $ideaId,
            ['title' => 'arschloch ist hier', 'body' => 'Saubere Beschreibung ohne Probleme hier.'],
            $userId,
        ));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('Honeypot', $body);
        self::assertStringNotContainsString('Bot', $body);
        self::assertStringNotContainsString('Security by Design', $body);
        self::assertStringNotContainsString('Spam-Schutz', $body);
        self::assertStringNotContainsString('Time-Trap', $body);
    }
}
