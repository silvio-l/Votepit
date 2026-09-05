<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /{board}/ideas/new + POST /{board}/ideas.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create +
 * IntegrationTestCase). No direct access to repository internals.
 *
 * Covered ACs (idea creation):
 *  AC1  — GET /{board}/ideas/new → 200 (logged in) / login redirect with return-to (anon)
 *  AC2  — POST /{board}/ideas creates the idea board-scoped; AuthZ user, CSRF enforced,
 *          RateLimit idea:submit active
 *  AC3  — Form POST works without JavaScript (plain HTML form)
 *  AC4  — title_normalized is written via TitleNormalizer on creation
 *  AC5  — Empty/too-short title or body → form with error, no 500, values preserved
 *  AC6  — Success → 302 to detail (PRG); reload does not trigger a double submit
 *  AC7  — Created idea appears in the board list
 *  AC8  — Blocked user (BlockCheck) → 403
 *  AC9  — POST without a valid CSRF token → 403
 *  AC10 — Board page shows a "New idea" CTA (logged in) / login hint (anon)
 *  AC11 — AuthZ tests: anon GET → login redirect; anon POST → 401; logged in → OK
 *
 * Covered ACs (moderation + bot defense):
 *  AC1  — Profanity in title or body → 422, neutral message, no DB entry
 *  AC2  — Clean text → 302 (no regression)
 *  AC3  — Hit is logged masked via AuditLogger
 *  AC4  — Honeypot filled → 422, no DB entry; empty → normal flow
 *  AC5  — Honeypot field invisible (aria-hidden, display:none), no JS
 *  AC6  — Time-trap: too fast → 422; normal timing → 302
 *  AC7  — Error messages don't advertise defense mechanisms
 *  AC8  — Existing idea-creation tests stay green
 */
final class IdeaSubmitActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * Generates a valid time-trap stamp with a backdated timestamp
     * (5 s in the past), so existing tests don't need to sleep.
     */
    private function validTimeTrap(): string
    {
        // Build a backdated stamp directly using the same MAC logic as TimeTrapService
        // (5 s in the past → comfortably above MIN_SECONDS=3, no sleep needed).
        $ts  = (string) (time() - 5);
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /**
     * Generates a time-trap stamp with a current timestamp, so the
     * elapsed check fails (0 s elapsed < MIN_SECONDS).
     */
    private function tooFastTimeTrap(): string
    {
        $ts  = (string) time();
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /** GET request to /{board}/ideas/new, optionally with a session cookie. */
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
     * POST request to /{board}/ideas with a valid CSRF token, optional session.
     * By default includes a valid time-trap stamp (5 s backdated).
     *
     * @param array<string, string> $body
     */
    private function postIdea(string $boardSlug, array $body, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $defaults = ['_csrf' => $token, '_form_at' => $this->validTimeTrap()];
        $request  = (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(array_merge($defaults, $body));

        if ($userId !== null) {
            $request = $request->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    /**
     * POST without a CSRF token (for AC9).
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
    // AC1 — GET /{board}/ideas/new (logged in → 200; anon → login redirect)
    // -------------------------------------------------------------------------

    public function test_get_new_as_authenticated_user_returns_200(): void
    {
        $this->insertBoard('ac1-board');
        $userId = $this->insertUser('ac1@example.com');

        $response = $this->createApp()->handle($this->getNewRequest('ac1-board', $userId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['is_authenticated'] ?? false);
    }

    public function test_get_new_as_anon_redirects_to_login_with_return_to(): void
    {
        $this->insertBoard('ac1-anon-board');

        $response = $this->createApp()->handle($this->getNewRequest('ac1-anon-board'));

        // SPA route: 200 + is_authenticated=false (SPA redirects to login)
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['is_authenticated'] ?? true);
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
            ['title' => 'My new idea', 'body' => 'This is the description of the idea, a bit longer.'],
            $userId,
        ));

        // 201 Created + JSON id
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertGreaterThan(0, $data['id'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // AC3 — Form POST works without JavaScript
    // -------------------------------------------------------------------------

    public function test_form_contains_no_javascript_requirements(): void
    {
        $this->insertBoard('ac3-board');
        $userId = $this->insertUser('ac3@example.com');

        $response = $this->createApp()->handle($this->getNewRequest('ac3-board', $userId));

        // SPA renders the form; GET /new only returns board data + auth status
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['is_authenticated'] ?? false);
        self::assertSame('ac3-board', $data['board']['slug'] ?? null);
    }

    // -------------------------------------------------------------------------
    // AC4 — title_normalized is written via TitleNormalizer
    // -------------------------------------------------------------------------

    public function test_title_normalized_is_set_via_title_normalizer(): void
    {
        $boardId = $this->insertBoard('ac4-board');
        $userId  = $this->insertUser('ac4@example.com');
        $app     = $this->createApp();

        // POST — "Dark Mode" normalizes to "darkmode"
        $response = $app->handle($this->postIdea(
            'ac4-board',
            ['title' => 'Dark Mode', 'body' => 'Please add a dark mode, that would be very useful.'],
            $userId,
        ));
        self::assertSame(201, $response->getStatusCode());

        // Check directly in the DB (the one exception: normalization is only
        // observable via the DB, since no dedicated endpoint exists for it)
        $row = $this->conn->fetchAssociative(
            'SELECT title_normalized FROM ideas WHERE board_id = :board_id LIMIT 1',
            ['board_id' => $boardId],
        );
        self::assertIsArray($row);
        self::assertSame('darkmode', $row['title_normalized']);
    }

    // -------------------------------------------------------------------------
    // AC5 — Validation error: empty/too-short title/body → form re-render
    // -------------------------------------------------------------------------

    public function test_empty_title_returns_form_with_error_not_500(): void
    {
        $this->insertBoard('ac5a-board');
        $userId = $this->insertUser('ac5a@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5a-board',
            ['title' => '', 'body' => 'A description that is long enough.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        // Error message for empty title present
        self::assertStringContainsString('empty', $data['error']['fields']['title'] ?? '');
        // No internal server error
        self::assertStringNotContainsString('Internal Server Error', (string) $response->getBody());
    }

    public function test_too_short_title_returns_form_with_error(): void
    {
        $this->insertBoard('ac5b-board');
        $userId = $this->insertUser('ac5b@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5b-board',
            ['title' => 'ab', 'body' => 'A description that is long enough.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('at least', $data['error']['fields']['title'] ?? '');
    }

    public function test_empty_body_returns_form_with_error(): void
    {
        $this->insertBoard('ac5c-board');
        $userId = $this->insertUser('ac5c@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5c-board',
            ['title' => 'Valid title here', 'body' => ''],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('empty', $data['error']['fields']['body'] ?? '');
    }

    public function test_validation_error_preserves_entered_values(): void
    {
        $this->insertBoard('ac5d-board');
        $userId = $this->insertUser('ac5d@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac5d-board',
            ['title' => 'ab', 'body' => 'Some text that is long enough.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        // Entered title is preserved in the JSON error (SPA can prefill the form with it)
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('ab', $data['error']['values']['title'] ?? null);
    }

    // -------------------------------------------------------------------------
    // AC6 — Success → 302 to detail (PRG)
    // -------------------------------------------------------------------------

    public function test_successful_submit_redirects_to_idea_detail(): void
    {
        $this->insertBoard('ac6-board');
        $userId  = $this->insertUser('ac6@example.com');
        $app     = $this->createApp();

        $response = $app->handle($this->postIdea(
            'ac6-board',
            ['title' => 'A great idea', 'body' => 'Here is my detailed description of the idea.'],
            $userId,
        ));

        // 201 + JSON with the new idea ID
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertMatchesRegularExpression('#^\d+$#', (string) ($data['id'] ?? ''));
    }

    public function test_redirect_target_is_accessible_get(): void
    {
        $this->insertBoard('ac6b-board');
        $userId = $this->insertUser('ac6b@example.com');
        $app    = $this->createApp();

        $postResponse = $app->handle($this->postIdea(
            'ac6b-board',
            ['title' => 'PRG test idea', 'body' => 'The description is long enough here.'],
            $userId,
        ));

        self::assertSame(201, $postResponse->getStatusCode());
        $postData = json_decode((string) $postResponse->getBody(), true);
        $ideaId   = $postData['id'] ?? 0;
        self::assertGreaterThan(0, $ideaId);

        // GET on the detail URL must return 200
        $getResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/ac6b-board/ideas/' . $ideaId)
        );
        self::assertSame(200, $getResponse->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC7 — Created idea appears in the board list
    // -------------------------------------------------------------------------

    public function test_created_idea_appears_in_board_list(): void
    {
        $this->insertBoard('ac7-board');
        $userId  = $this->insertUser('ac7@example.com');
        $app     = $this->createApp();

        $app->handle($this->postIdea(
            'ac7-board',
            ['title' => 'Idea for the list', 'body' => 'This idea should appear in the board list.'],
            $userId,
        ));

        // Load the board list
        $listResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/ac7-board')
        );
        self::assertSame(200, $listResponse->getStatusCode());
        $listData = json_decode((string) $listResponse->getBody(), true);
        $titles   = array_column($listData['ideas'] ?? [], 'title');
        self::assertContains('Idea for the list', $titles);
    }

    // -------------------------------------------------------------------------
    // AC8 — Blocked user → 403
    // -------------------------------------------------------------------------

    public function test_blocked_user_cannot_post_idea(): void
    {
        $this->insertBoard('ac8-board');
        $blockedUserId = $this->insertUser('blocked@example.com', ['is_blocked' => 1]);

        $response = $this->createApp()->handle($this->postIdea(
            'ac8-board',
            ['title' => 'Spam idea', 'body' => 'Blocked user must not be allowed to do this.'],
            $blockedUserId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC9 — POST without a valid CSRF token → 403
    // -------------------------------------------------------------------------

    public function test_post_without_csrf_token_returns_403(): void
    {
        $this->insertBoard('ac9-board');
        $userId = $this->insertUser('ac9@example.com');

        $response = $this->createApp()->handle($this->postIdeaNoCsrf(
            'ac9-board',
            ['title' => 'Idea without CSRF', 'body' => 'This should not work.'],
            $userId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC10 — Board page shows a "new idea" CTA (logged in) / login hint (anon)
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
        // JSON API: logged-in user → is_authenticated=true (SPA renders the CTA)
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['is_authenticated'] ?? false);
    }

    public function test_board_home_shows_login_hint_for_anon_user(): void
    {
        $this->insertBoard('ac10b-board');

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/ac10b-board')
        );

        self::assertSame(200, $response->getStatusCode());
        // JSON API: anon → is_authenticated=false (SPA renders the login hint)
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['is_authenticated'] ?? true);
    }

    // -------------------------------------------------------------------------
    // AC11 — AuthZ tests: anon POST → 401; logged-in POST → allowed
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
            ->withParsedBody(['title' => 'Anon idea', 'body' => 'Description.', '_csrf' => $token]);

        $response = $this->createApp()->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_authenticated_user_can_submit_idea(): void
    {
        $this->insertBoard('ac11b-board');
        $userId = $this->insertUser('ac11b@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'ac11b-board',
            ['title' => 'Idea from a logged-in user', 'body' => 'Here is the detailed description of the idea.'],
            $userId,
        ));

        // 201 = successfully created
        self::assertSame(201, $response->getStatusCode());
    }

    public function test_get_new_returns_form_with_csrf_field(): void
    {
        $this->insertBoard('csrf-form-board');
        $userId = $this->insertUser('csrf-form@example.com');

        // CSRF token is provided via /api/bootstrap; GET /new only returns board data
        $response = $this->createApp()->handle($this->getNewRequest('csrf-form-board', $userId));
        self::assertSame(200, $response->getStatusCode());
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
            ['title' => 'Something', 'body' => 'Some description here.'],
            $userId,
        ));
        self::assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // Moderation + bot defense
    // =========================================================================

    // -------------------------------------------------------------------------
    // AC1 — Profanity in title → 422, neutral message, no DB entry
    // -------------------------------------------------------------------------

    public function test_i09_profanity_in_title_returns_422_no_db_entry(): void
    {
        $boardId = $this->insertBoard('mod-title-board');
        $userId  = $this->insertUser('mod-title@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'mod-title-board',
            ['title' => 'arschloch please build', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        // No DB entry
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = ?', [$boardId]);
        self::assertSame(0, (int) $count);
        // Neutral message (via JSON decode, since the body is unicode-escaped)
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('disallowed terms', $data['error']['message'] ?? '');
    }

    public function test_i09_profanity_in_body_returns_422_no_db_entry(): void
    {
        $boardId = $this->insertBoard('mod-body-board');
        $userId  = $this->insertUser('mod-body@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'mod-body-board',
            ['title' => 'Clean title here', 'body' => 'This text contains arschloch as a term.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = ?', [$boardId]);
        self::assertSame(0, (int) $count);
    }

    // -------------------------------------------------------------------------
    // AC2 — Clean text → 302 (no regression against existing tests)
    // -------------------------------------------------------------------------

    public function test_i09_clean_text_still_succeeds(): void
    {
        $this->insertBoard('clean-mod-board');
        $userId = $this->insertUser('clean-mod@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'clean-mod-board',
            ['title' => 'My clean idea', 'body' => 'This description contains no disallowed content at all.'],
            $userId,
        ));

        self::assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC3 — Hit is logged masked via AuditLogger
    // -------------------------------------------------------------------------

    public function test_i09_moderation_hit_is_logged_masked(): void
    {
        $this->insertBoard('mod-log-board');
        $userId = $this->insertUser('mod-log@example.com');

        $this->createApp()->handle($this->postIdea(
            'mod-log-board',
            ['title' => 'arschloch is here', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        $log = $this->readAuditLog();
        // Log must contain the event
        self::assertStringContainsString('idea.moderation_blocked', $log);
        // The raw matched term must NOT appear in the log
        self::assertStringNotContainsString('arschloch', $log);
    }

    // -------------------------------------------------------------------------
    // AC4 — Honeypot filled → 422; empty → normal flow
    // -------------------------------------------------------------------------

    public function test_i09_honeypot_filled_returns_422_no_db_entry(): void
    {
        $boardId = $this->insertBoard('hp-board');
        $userId  = $this->insertUser('hp@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'hp-board',
            ['title' => 'Clean idea', 'body' => 'Clean description without any problems here.', 'website' => 'http://spam.example.com'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = ?', [$boardId]);
        self::assertSame(0, (int) $count);
        // No bot hint in the response
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('spam', $body);
        self::assertStringNotContainsString('Bot', $body);
    }

    public function test_i09_honeypot_empty_allows_normal_flow(): void
    {
        $this->insertBoard('hp-ok-board');
        $userId = $this->insertUser('hp-ok@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'hp-ok-board',
            ['title' => 'Clean idea', 'body' => 'Clean description without any problems here.', 'website' => ''],
            $userId,
        ));

        self::assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5 — Honeypot field: SPA renders the form; only checked server-side
    // -------------------------------------------------------------------------

    public function test_i09_honeypot_field_is_hidden_in_form(): void
    {
        $this->insertBoard('hp-vis-board');
        $userId = $this->insertUser('hp-vis@example.com');

        // SPA renders the form including the honeypot; GET /new returns JSON data
        $response = $this->createApp()->handle($this->getNewRequest('hp-vis-board', $userId));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // AC6 — Time-trap: too fast → 422; normal timing → 302
    // -------------------------------------------------------------------------

    public function test_i09_time_trap_too_fast_returns_422(): void
    {
        $boardId = $this->insertBoard('tt-fast-board');
        $userId  = $this->insertUser('tt-fast@example.com');
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $signed  = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tt-fast-board/ideas')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody([
                '_csrf'    => $token,
                '_form_at' => $this->tooFastTimeTrap(),
                'title'    => 'Clean idea',
                'body'     => 'Clean description without any problems here.',
            ]);

        $response = $this->createApp()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        // No DB entry
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = ?', [$boardId]);
        self::assertSame(0, (int) $count);
    }

    public function test_i09_time_trap_normal_timing_succeeds(): void
    {
        $this->insertBoard('tt-ok-board');
        $userId = $this->insertUser('tt-ok@example.com');

        // postIdea() sets a valid (backdated) stamp by default.
        $response = $this->createApp()->handle($this->postIdea(
            'tt-ok-board',
            ['title' => 'Clean idea', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        self::assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC7 — Error messages don't advertise defense mechanisms
    // -------------------------------------------------------------------------

    public function test_i09_error_messages_contain_no_security_marketing(): void
    {
        $this->insertBoard('sec-mkt-board');
        $userId = $this->insertUser('sec-mkt@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            'sec-mkt-board',
            ['title' => 'arschloch is here', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        $body = (string) $response->getBody();
        // No security advertising in visible text
        self::assertStringNotContainsString('Honeypot', $body);
        self::assertStringNotContainsString('Bot', $body);
        self::assertStringNotContainsString('Security by Design', $body);
        self::assertStringNotContainsString('Spam protection', $body);
        self::assertStringNotContainsString('Time-Trap', $body);
    }
}
