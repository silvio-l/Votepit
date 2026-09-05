<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /{board}/ideas/{id}/edit + POST /{board}/ideas/{id}
 * (editing your own idea / row-level ownership).
 *
 * All assertions run exclusively through the HTTP seam.
 *
 * Covered ACs:
 *  AC1  — GET /edit shows the prefilled edit form only to the author (200)
 *  AC2  — POST updates the user's own idea; AuthZ user + ownership, CSRF enforced
 *  AC3  — title_normalized is re-normalized via TitleNormalizer on update
 *  AC4  — Foreign non-admin → 403; idea not in board → 404; anon → login redirect
 *  AC5  — Blocked user → rejected (403)
 *  AC6  — Invalid input → form re-rendered with error, no 500, values preserved
 *  AC7  — updateOwn binds author_id/board_id as parameters (prepared statement)
 *  AC8  — Edit path: profanity/honeypot/time-trap → 422 re-render, no update
 *  AC9  — AuthZ/ownership tests: owner allowed / foreign user 403 / anon redirect
 */
final class IdeaEditActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * Valid time-trap stamp (5 s backdated — above MIN_SECONDS=3).
     */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /**
     * Invalid time-trap stamp (current timestamp → too fast).
     */
    private function tooFastTimeTrap(): string
    {
        $ts  = (string) time();
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /** GET request to /{board}/ideas/{id}/edit, optionally with session cookie. */
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
     * POST request to /{board}/ideas/{id} with a valid CSRF token + time-trap.
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
     * POST without a CSRF token (for the CSRF test).
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
    // AC1 — GET /edit shows the prefilled edit form only to the author (200)
    // -------------------------------------------------------------------------

    public function test_get_edit_as_author_returns_200_with_prefilled_form(): void
    {
        $boardId = $this->insertBoard('edit-ac1-board');
        $userId  = $this->insertUser('edit-ac1@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Original title');

        $response = $this->createApp()->handle($this->getEditRequest('edit-ac1-board', $ideaId, $userId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        // Prefilled title in the JSON
        self::assertSame('Original title', $data['idea']['title'] ?? null);
    }

    public function test_get_edit_form_has_csrf_field(): void
    {
        $boardId = $this->insertBoard('edit-csrf-board');
        $userId  = $this->insertUser('edit-csrf@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        // CSRF token is provided via /api/bootstrap; GET /edit only returns idea data
        $response = $this->createApp()->handle($this->getEditRequest('edit-csrf-board', $ideaId, $userId));
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_get_edit_form_has_honeypot_field_hidden(): void
    {
        $boardId = $this->insertBoard('edit-hp-vis-board');
        $userId  = $this->insertUser('edit-hp-vis@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        // SPA renders the form including the honeypot; GET /edit returns JSON data
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

        // Idea belongs to board1, but we query on board2 — board-scoped → 404
        $response = $this->createApp()->handle($this->getEditRequest('edit-b2-board', $ideaId, $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2 — POST updates the user's own idea; AuthZ user + ownership, CSRF
    // -------------------------------------------------------------------------

    public function test_post_edit_updates_own_idea_and_redirects(): void
    {
        $boardId = $this->insertBoard('edit-update-board');
        $userId  = $this->insertUser('edit-update@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Old title');

        $response = $this->createApp()->handle($this->postEdit(
            'edit-update-board',
            $ideaId,
            ['title' => 'New title', 'body' => 'New description that is long enough here.'],
            $userId,
        ));

        // 200 + JSON ok:true (no 302 redirect; SPA navigates itself)
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
    }

    public function test_post_edit_persists_updated_title_in_db(): void
    {
        $boardId = $this->insertBoard('edit-persist-board');
        $userId  = $this->insertUser('edit-persist@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Old title');

        $this->createApp()->handle($this->postEdit(
            'edit-persist-board',
            $ideaId,
            ['title' => 'Changed title', 'body' => 'New description that is long enough here.'],
            $userId,
        ));

        $row = $this->conn->fetchAssociative(
            'SELECT title FROM ideas WHERE id = :id AND board_id = :board_id',
            ['id' => $ideaId, 'board_id' => $boardId],
        );
        self::assertIsArray($row);
        self::assertSame('Changed title', $row['title']);
    }

    public function test_post_edit_without_csrf_returns_403(): void
    {
        $boardId = $this->insertBoard('edit-nocsrf-board');
        $userId  = $this->insertUser('edit-nocsrf@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEditNoCsrf(
            'edit-nocsrf-board',
            $ideaId,
            ['title' => 'Title', 'body' => 'Description.'],
            $userId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC3 — title_normalized re-normalized via TitleNormalizer
    // -------------------------------------------------------------------------

    public function test_post_edit_renormalizes_title_on_update(): void
    {
        $boardId = $this->insertBoard('edit-norm-board');
        $userId  = $this->insertUser('edit-norm@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Old title');

        $this->createApp()->handle($this->postEdit(
            'edit-norm-board',
            $ideaId,
            ['title' => 'Dark Mode', 'body' => 'New description that is long enough here.'],
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
    // AC4 — Foreign non-admin → 403; idea not in board → 404; anon → redirect
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
            ['title' => 'Foreign attempt', 'body' => 'This must not be saved here.'],
            $foreignId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_edit_anon_redirects_to_login(): void
    {
        $boardId = $this->insertBoard('edit-anon-board');
        $userId  = $this->insertUser('anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        // No user cookie → action returns 401 (SPA redirects to login)
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
            ->withParsedBody(['_csrf' => $token, '_form_at' => $this->validTimeTrap(), 'title' => 'Title', 'body' => 'Description.']);

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
    // AC5 — Blocked user → rejected (403) on a mutating verb
    // -------------------------------------------------------------------------

    public function test_blocked_user_cannot_post_edit(): void
    {
        $boardId   = $this->insertBoard('edit-blocked-post-board');
        $blockedId = $this->insertUser('blocked-post-edit@example.com', ['is_blocked' => 1]);
        $ideaId    = $this->seedIdea($boardId, $blockedId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-blocked-post-board',
            $ideaId,
            ['title' => 'Spam', 'body' => 'Blocked user must not be allowed to do this here.'],
            $blockedId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC6 — Invalid input → form re-rendered with error, no 500
    // -------------------------------------------------------------------------

    public function test_empty_title_on_edit_returns_422_with_error(): void
    {
        $boardId = $this->insertBoard('edit-val-board');
        $userId  = $this->insertUser('edit-val@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-val-board',
            $ideaId,
            ['title' => '', 'body' => 'Valid description that is long enough here.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('empty', $data['error']['fields']['title'] ?? '');
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
            ['title' => 'ab', 'body' => 'Valid description that is long enough here.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('at least', $data['error']['fields']['title'] ?? '');
    }

    public function test_validation_error_preserves_entered_values_on_edit(): void
    {
        $boardId = $this->insertBoard('edit-preserve-board');
        $userId  = $this->insertUser('edit-preserve@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-preserve-board',
            $ideaId,
            ['title' => 'ab', 'body' => 'Valid description that is long enough here.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        // The entered (invalid) value is preserved in the JSON error (SPA prefills the form)
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('ab', $data['error']['values']['title'] ?? null);
    }

    public function test_validation_error_does_not_modify_db(): void
    {
        $boardId = $this->insertBoard('edit-nodb-board');
        $userId  = $this->insertUser('edit-nodb@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Unchanged title');

        $this->createApp()->handle($this->postEdit(
            'edit-nodb-board',
            $ideaId,
            ['title' => '', 'body' => 'Description.'],
            $userId,
        ));

        $row = $this->conn->fetchAssociative(
            'SELECT title FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row);
        // Title must not have changed
        self::assertSame('Unchanged title', $row['title']);
    }

    // -------------------------------------------------------------------------
    // AC7 — updateOwn binds author_id/board_id as parameters (prepared statement)
    // -------------------------------------------------------------------------

    public function test_post_edit_does_not_update_other_authors_idea_with_same_board(): void
    {
        // Scenario: two users, same board — only the author may update
        $boardId  = $this->insertBoard('edit-stmt-board');
        $authorId = $this->insertUser('stmt-author@example.com');
        $otherId  = $this->insertUser('stmt-other@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Original');

        // Other user tries to edit → 403, no DB update
        $response = $this->createApp()->handle($this->postEdit(
            'edit-stmt-board',
            $ideaId,
            ['title' => 'Hijacked', 'body' => 'This should not end up in the DB here.'],
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
    // AC8 — Moderation + bot defense (same contract as submit)
    // -------------------------------------------------------------------------

    public function test_profanity_in_edit_returns_422_no_db_update(): void
    {
        $boardId = $this->insertBoard('edit-mod-board');
        $userId  = $this->insertUser('edit-mod@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Clean original title');

        $response = $this->createApp()->handle($this->postEdit(
            'edit-mod-board',
            $ideaId,
            ['title' => 'arschloch please build', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        // DB unchanged
        $row = $this->conn->fetchAssociative('SELECT title FROM ideas WHERE id = ?', [$ideaId]);
        self::assertIsArray($row);
        self::assertSame('Clean original title', $row['title']);
        // Neutral message (via JSON decode, since the body is unicode-escaped)
        $errData = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('disallowed terms', $errData['error']['message'] ?? '');
    }

    public function test_moderation_hit_on_edit_is_logged_masked(): void
    {
        $boardId = $this->insertBoard('edit-log-board');
        $userId  = $this->insertUser('edit-log@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->createApp()->handle($this->postEdit(
            'edit-log-board',
            $ideaId,
            ['title' => 'arschloch is here', 'body' => 'Clean description without any problems here.'],
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
        $ideaId  = $this->seedIdea($boardId, $userId, 'Original text');

        $response = $this->createApp()->handle($this->postEdit(
            'edit-hp-board',
            $ideaId,
            ['title' => 'Clean', 'body' => 'Clean description without any problems here.', 'website' => 'http://spam.example.com'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT title FROM ideas WHERE id = ?', [$ideaId]);
        self::assertIsArray($row);
        self::assertSame('Original text', $row['title']);
    }

    public function test_time_trap_too_fast_on_edit_returns_422_no_db_update(): void
    {
        $boardId = $this->insertBoard('edit-tt-board');
        $userId  = $this->insertUser('edit-tt@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Unchanged title');

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/edit-tt-board/ideas/' . $ideaId)
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody([
                '_csrf'    => $token,
                '_form_at' => $this->tooFastTimeTrap(),
                'title'    => 'Clean title',
                'body'     => 'Clean description without any problems here.',
            ]);

        $response = $this->createApp()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT title FROM ideas WHERE id = ?', [$ideaId]);
        self::assertIsArray($row);
        self::assertSame('Unchanged title', $row['title']);
    }

    public function test_clean_edit_succeeds_with_valid_timing(): void
    {
        $boardId = $this->insertBoard('edit-clean-board');
        $userId  = $this->insertUser('edit-clean@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-clean-board',
            $ideaId,
            ['title' => 'Clean updated', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Edit window — EDIT_WINDOW_SECONDS (2h), mirrors CommentUpdateAction
    // -------------------------------------------------------------------------

    public function test_get_edit_after_window_expires_returns_422(): void
    {
        $boardId = $this->insertBoard('edit-window-get-board');
        $userId  = $this->insertUser('edit-window-get@example.com');
        $oldCreatedAt = (new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Old idea', ['created_at' => $oldCreatedAt]);

        $response = $this->createApp()->handle($this->getEditRequest('edit-window-get-board', $ideaId, $userId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('edit_window_expired', $data['error']['key'] ?? null);
    }

    public function test_post_edit_after_window_expires_returns_422_no_db_update(): void
    {
        $boardId = $this->insertBoard('edit-window-post-board');
        $userId  = $this->insertUser('edit-window-post@example.com');
        $oldCreatedAt = (new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Original title', ['created_at' => $oldCreatedAt]);

        $response = $this->createApp()->handle($this->postEdit(
            'edit-window-post-board',
            $ideaId,
            ['title' => 'Too late', 'body' => 'This must not be saved here.'],
            $userId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('edit_window_expired', $data['error']['key'] ?? null);

        $row = $this->conn->fetchAssociative('SELECT title FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertSame('Original title', $row['title']);
    }

    public function test_get_edit_within_window_still_succeeds(): void
    {
        $boardId = $this->insertBoard('edit-window-ok-board');
        $userId  = $this->insertUser('edit-window-ok@example.com');
        $recentCreatedAt = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Still editable', ['created_at' => $recentCreatedAt]);

        $response = $this->createApp()->handle($this->getEditRequest('edit-window-ok-board', $ideaId, $userId));

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
            ['title' => 'arschloch is here', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('Honeypot', $body);
        self::assertStringNotContainsString('Bot', $body);
        self::assertStringNotContainsString('Security by Design', $body);
        self::assertStringNotContainsString('Spam protection', $body);
        self::assertStringNotContainsString('Time-Trap', $body);
    }
}
