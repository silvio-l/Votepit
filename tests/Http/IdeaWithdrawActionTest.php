<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für POST /{board}/ideas/{id}/withdraw
 * (Sprint 3, Issue 07 — Eigene Idee zurückziehen / Hard-Delete / Row-Level-Ownership).
 *
 * Alle Assertions laufen ausschließlich durch den HTTP-Seam.
 *
 * Abgedeckte ACs:
 *  AC1  — POST löscht die eigene Idee (Hard-Delete); AuthZ user + Ownership, CSRF erzwungen
 *  AC2  — withdraw bindet id AND author_id als Parameter (Prepared-Statement) —
 *          fremde Idee wird nie gelöscht
 *  AC3  — Fremder Non-Admin → 403; Idee nicht im Board → 404; anonym → 401; blockiert → 403
 *  AC4  — Zurückgezogene Idee verschwindet aus der Board-Liste AND Detail → 404
 *  AC5  — POST ohne gültiges CSRF-Token → 403 abgewiesen
 *  AC6  — Ownership-Tests: Owner erlaubt / fremder User 403 und Idee bleibt in DB
 */
final class IdeaWithdrawActionTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * POST-Request auf /{board}/ideas/{id}/withdraw mit gültigem CSRF-Token.
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
     * POST ohne CSRF-Token (für CSRF-Test).
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
     * GET-Request auf /{board}/ideas/{id} (Detail) — um nach Withdraw 404 zu prüfen.
     */
    private function getDetail(string $boardSlug, int $ideaId): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug . '/ideas/' . $ideaId);
    }

    /**
     * GET-Request auf /{board} (Board-Home / Ideenliste).
     */
    private function getBoardHome(string $boardSlug): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/' . $boardSlug);
    }

    // -------------------------------------------------------------------------
    // AC1 — POST löscht die eigene Idee; Redirect auf Board-Home
    // -------------------------------------------------------------------------

    public function test_owner_can_withdraw_own_idea_and_gets_302_redirect_to_board_home(): void
    {
        $boardId = $this->insertBoard('withdraw-ac1-board');
        $userId  = $this->insertUser('withdraw-ac1@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Eigene Idee');

        $response = $this->createApp()->handle($this->postWithdraw('withdraw-ac1-board', $ideaId, $userId));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/withdraw-ac1-board', $response->getHeaderLine('Location'));
    }

    public function test_owner_withdraw_deletes_idea_from_db(): void
    {
        $boardId = $this->insertBoard('withdraw-db-board');
        $userId  = $this->insertUser('withdraw-db@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Zu löschende Idee');

        $this->createApp()->handle($this->postWithdraw('withdraw-db-board', $ideaId, $userId));

        $row = $this->conn->fetchAssociative(
            'SELECT id FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertFalse($row, 'Idee muss nach Withdraw aus der DB gelöscht sein.');
    }

    // -------------------------------------------------------------------------
    // AC4 — Zurückgezogene Idee verschwindet aus Liste AND Detail → 404
    // -------------------------------------------------------------------------

    public function test_withdrawn_idea_returns_404_on_detail(): void
    {
        $boardId = $this->insertBoard('withdraw-detail-board');
        $userId  = $this->insertUser('withdraw-detail@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Bald gelöscht');

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
        self::assertStringNotContainsString('Unique Title XYZ123', (string) $listResponse->getBody());
    }

    // -------------------------------------------------------------------------
    // AC2 + AC6 — withdraw bindet author_id; fremde Idee → 403, bleibt in DB
    // -------------------------------------------------------------------------

    public function test_foreign_user_gets_403_and_idea_not_deleted(): void
    {
        $boardId   = $this->insertBoard('withdraw-403-board');
        $authorId  = $this->insertUser('withdraw-author@example.com');
        $foreignId = $this->insertUser('withdraw-foreign@example.com');
        $ideaId    = $this->seedIdea($boardId, $authorId, 'Fremde Idee');

        $response = $this->createApp()->handle($this->postWithdraw('withdraw-403-board', $ideaId, $foreignId));

        self::assertSame(403, $response->getStatusCode());

        // Idee muss noch in der DB sein
        $row = $this->conn->fetchAssociative(
            'SELECT id FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row, 'Idee muss nach abgelehntem Withdraw noch in der DB sein.');
    }

    public function test_foreign_user_withdraw_does_not_delete_via_sql_either(): void
    {
        // Defense-in-Depth: withdraw() WHERE bindet author_id — selbst wenn Action 403 gibt,
        // darf kein DB-Delete einer fremden Idee möglich sein.
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
    // AC3 — Idee nicht im Board → 404
    // -------------------------------------------------------------------------

    public function test_idea_from_other_board_returns_404(): void
    {
        $boardId1 = $this->insertBoard('withdraw-b1-board');
        $this->insertBoard('withdraw-b2-board');
        $userId   = $this->insertUser('withdraw-cross@example.com');
        $ideaId   = $this->seedIdea($boardId1, $userId);

        // Idee gehört zu Board1, Request geht an Board2
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
    // AC3 — Anonym → 401 (AuthZMiddleware::user() greift vor der Action)
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
    // AC3 — Blockierter Nutzer → 403 (BlockCheckMiddleware greift vor der Action)
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
    // AC5 — POST ohne CSRF-Token → 403 abgewiesen
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
        self::assertIsArray($row, 'Idee muss nach CSRF-Reject noch in der DB sein.');
    }

    // -------------------------------------------------------------------------
    // Audit-Log
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
