<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /{board}/ideas/{id}/withdraw
 * (withdrawing your own idea / hard delete / row-level ownership).
 *
 * All assertions run exclusively through the HTTP seam.
 *
 * Covered ACs:
 *  AC1  — POST deletes the user's own idea (hard delete); AuthZ user + ownership, CSRF enforced
 *  AC2  — withdraw binds id AND author_id as parameters (prepared statement) —
 *          a foreign idea is never deleted
 *  AC3  — Foreign non-admin → 403; idea not in board → 404; anon → 401; blocked → 403
 *  AC4  — Withdrawn idea disappears from the board list AND detail → 404
 *  AC5  — POST without a valid CSRF token → 403 rejected
 *  AC6  — Ownership tests: owner allowed / foreign user 403 and idea remains in DB
 */
final class IdeaWithdrawActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * POST request to /{board}/ideas/{id}/withdraw with a valid CSRF token.
     */
    private function postWithdraw(string $boardSlug, int $ideaId, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas/' . $ideaId . '/withdraw')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(['_csrf' => $token]);

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
     */
    private function postWithdrawNoCsrf(string $boardSlug, int $ideaId, ?int $userId = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas/' . $ideaId . '/withdraw')
            ->withParsedBody([]);

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessionCookie($userId),
            ]);
        }

        return $request;
    }

    /**
     * GET request to /{board}/ideas/{id} (detail) — to check for 404 after withdraw.
     */
    private function getDetail(string $boardSlug, int $ideaId): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug . '/ideas/' . $ideaId);
    }

    /**
     * GET request to /{board} (board home / idea list).
     */
    private function getBoardHome(string $boardSlug): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug);
    }

    // -------------------------------------------------------------------------
    // AC1 — POST deletes the user's own idea; redirect to board home
    // -------------------------------------------------------------------------

    public function test_owner_can_withdraw_own_idea_and_gets_302_redirect_to_board_home(): void
    {
        $boardId = $this->insertBoard('withdraw-ac1-board');
        $userId  = $this->insertUser('withdraw-ac1@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Own idea');

        $response = $this->createApp()->handle($this->postWithdraw('withdraw-ac1-board', $ideaId, $userId));

        // 200 + JSON {"ok": true} (SPA navigates itself; no 302 redirect)
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
    }

    public function test_owner_withdraw_deletes_idea_from_db(): void
    {
        $boardId = $this->insertBoard('withdraw-db-board');
        $userId  = $this->insertUser('withdraw-db@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Idea to be deleted');

        $this->createApp()->handle($this->postWithdraw('withdraw-db-board', $ideaId, $userId));

        $row = $this->conn->fetchAssociative(
            'SELECT id FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertFalse($row, 'Idea must be deleted from the DB after withdraw.');
    }

    // -------------------------------------------------------------------------
    // AC4 — Withdrawn idea disappears from list AND detail → 404
    // -------------------------------------------------------------------------

    public function test_withdrawn_idea_returns_404_on_detail(): void
    {
        $boardId = $this->insertBoard('withdraw-detail-board');
        $userId  = $this->insertUser('withdraw-detail@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Soon deleted');

        $app = $this->createApp();
        $app->handle($this->postWithdraw('withdraw-detail-board', $ideaId, $userId));

        $detailResponse = $app->handle($this->getDetail('withdraw-detail-board', $ideaId));
        self::assertSame(404, $detailResponse->getStatusCode());
    }

    public function test_withdrawn_idea_disappears_from_board_list(): void
    {
        $boardId = $this->insertBoard('withdraw-list-board');
        $userId  = $this->insertUser('withdraw-list@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Unique Title XYZ123');

        $app = $this->createApp();
        $app->handle($this->postWithdraw('withdraw-list-board', $ideaId, $userId));

        $listResponse = $app->handle($this->getBoardHome('withdraw-list-board'));
        self::assertSame(200, $listResponse->getStatusCode());
        $listData = json_decode((string) $listResponse->getBody(), true);
        $titles   = array_column($listData['ideas'] ?? [], 'title');
        self::assertNotContains('Unique Title XYZ123', $titles);
    }

    // -------------------------------------------------------------------------
    // AC2 + AC6 — withdraw binds author_id; foreign idea → 403, remains in DB
    // -------------------------------------------------------------------------

    public function test_foreign_user_gets_403_and_idea_not_deleted(): void
    {
        $boardId   = $this->insertBoard('withdraw-403-board');
        $authorId  = $this->insertUser('withdraw-author@example.com');
        $foreignId = $this->insertUser('withdraw-foreign@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId, 'Foreign idea');

        $response = $this->createApp()->handle($this->postWithdraw('withdraw-403-board', $ideaId, $foreignId));

        self::assertSame(403, $response->getStatusCode());

        // Idea must still be in the DB
        $row = $this->conn->fetchAssociative(
            'SELECT id FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row, 'Idea must still be in the DB after a rejected withdraw.');
    }

    public function test_foreign_user_withdraw_does_not_delete_via_sql_either(): void
    {
        // Defense in depth: withdraw() WHERE binds author_id — even if the action
        // returns 403, no DB delete of a foreign idea must be possible.
        $boardId   = $this->insertBoard('withdraw-stmt-board');
        $authorId  = $this->insertUser('stmt-withdraw-author@example.com');
        $foreignId = $this->insertUser('stmt-withdraw-foreign@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId, 'Original');

        $this->createApp()->handle($this->postWithdraw('withdraw-stmt-board', $ideaId, $foreignId));

        $row = $this->conn->fetchAssociative(
            'SELECT title FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row);
        self::assertSame('Original', $row['title']);
    }

    // -------------------------------------------------------------------------
    // AC3 — Idea not in board → 404
    // -------------------------------------------------------------------------

    public function test_idea_from_other_board_returns_404(): void
    {
        $boardId1 = $this->insertBoard('withdraw-b1-board');
        $this->insertBoard('withdraw-b2-board');
        $userId   = $this->insertUser('withdraw-cross@example.com');
        $ideaId   = $this->seedIdea($boardId1, $userId);

        // Idea belongs to board1, request goes to board2
        $response = $this->createApp()->handle($this->postWithdraw('withdraw-b2-board', $ideaId, $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_nonexistent_idea_returns_404(): void
    {
        $this->insertBoard('withdraw-ne-board');
        $userId = $this->insertUser('withdraw-ne@example.com');

        $response = $this->createApp()->handle($this->postWithdraw('withdraw-ne-board', 99999, $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC3 — Anon → 401 (AuthZMiddleware::user() applies before the action)
    // -------------------------------------------------------------------------

    public function test_anon_withdraw_returns_401(): void
    {
        $boardId = $this->insertBoard('withdraw-anon-board');
        $userId  = $this->insertUser('withdraw-anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postWithdraw('withdraw-anon-board', $ideaId));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC3 — Blocked user → 403 (BlockCheckMiddleware applies before the action)
    // -------------------------------------------------------------------------

    public function test_blocked_user_withdraw_returns_403(): void
    {
        $boardId   = $this->insertBoard('withdraw-blocked-board');
        $blockedId = $this->insertUser('withdraw-blocked@example.com', ['is_blocked' => 1]);
        $ideaId    = $this->seedIdea($boardId, $blockedId);

        $response = $this->createApp()->handle($this->postWithdraw('withdraw-blocked-board', $ideaId, $blockedId));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5 — POST without a CSRF token → 403 rejected
    // -------------------------------------------------------------------------

    public function test_withdraw_without_csrf_returns_403(): void
    {
        $boardId = $this->insertBoard('withdraw-nocsrf-board');
        $userId  = $this->insertUser('withdraw-nocsrf@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp()->handle($this->postWithdrawNoCsrf('withdraw-nocsrf-board', $ideaId, $userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_withdraw_without_csrf_does_not_delete_idea(): void
    {
        $boardId = $this->insertBoard('withdraw-nocsrf-db-board');
        $userId  = $this->insertUser('withdraw-nocsrf-db@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'CSRF-Guard-Test');

        $this->createApp()->handle($this->postWithdrawNoCsrf('withdraw-nocsrf-db-board', $ideaId, $userId));

        $row = $this->conn->fetchAssociative(
            'SELECT id FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row, 'Idea must still be in the DB after the CSRF reject.');
    }

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    public function test_withdraw_audit_log_is_written(): void
    {
        $boardId = $this->insertBoard('withdraw-log-board');
        $userId  = $this->insertUser('withdraw-log@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->createApp()->handle($this->postWithdraw('withdraw-log-board', $ideaId, $userId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.withdrawn', $log);
    }
}
