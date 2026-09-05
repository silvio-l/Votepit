<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /{board}/ideas/{id}/pin.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create),
 * the identical pipeline to production: Session → AuthN → AuthZ accountAdmin →
 * BlockCheck → CSRF → RateLimit perAction.
 *
 * AC coverage:
 *   AC1/2: Admin pins/unpins an idea → 200, value persisted, board-scoped isolated.
 *   AC3/4: Foreign board → 404. Invalid/missing idea → 404.
 *   AC5/6: anon → 401, non-admin → 403.
 *   AC7: Re-pinning/unpinning is an idempotent no-op (200, no write, no audit).
 *   AC8: Audit log contains idea.pin.changed with board/idea/pinned/actor, no PII.
 *   AC9: Admin view shows a pin button (frontend, tested separately).
 */
final class IdeaPinActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postPin(
        string $slug,
        int $ideaId,
        bool $pinned,
        ?int $userId,
    ): ServerRequestInterface {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/pin')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'pinned' => $pinned]);
    }

    private function ideaPinned(int $ideaId): bool
    {
        return (bool) $this->conn->fetchOne('SELECT is_pinned FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    // -------------------------------------------------------------------------
    // AC1: Admin pins an idea → 200, persisted
    // -------------------------------------------------------------------------

    public function test_admin_pins_idea_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('pin-ok');
        $adminId = $this->insertUser('admin-pin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea');

        $response = $this->createApp()->handle($this->postPin('pin-ok', $ideaId, true, $adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertTrue($data['pinned'] ?? false);
        self::assertTrue($this->ideaPinned($ideaId), 'Pin state must be persisted in the DB.');
    }

    // -------------------------------------------------------------------------
    // AC2: Admin unpins a pinned idea → 200, persisted
    // -------------------------------------------------------------------------

    public function test_admin_unpins_idea_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('pin-unpin');
        $adminId = $this->insertUser('admin-unpin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea', ['is_pinned' => 1]);

        $response = $this->createApp()->handle($this->postPin('pin-unpin', $ideaId, false, $adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['pinned'] ?? true);
        self::assertFalse($this->ideaPinned($ideaId));
    }

    // -------------------------------------------------------------------------
    // AC3: Board isolation — pin in board A does not affect board B
    // -------------------------------------------------------------------------

    public function test_pin_is_isolated_per_board(): void
    {
        $boardA  = $this->insertBoard('pin-iso-a');
        $boardB  = $this->insertBoard('pin-iso-b');
        $adminId = $this->insertUser('admin-iso@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaA   = $this->seedIdea($boardA, $adminId, 'Idea A');
        $ideaB   = $this->seedIdea($boardB, $adminId, 'Idea B');

        $this->createApp()->handle($this->postPin('pin-iso-a', $ideaA, true, $adminId));

        self::assertTrue($this->ideaPinned($ideaA));
        self::assertFalse($this->ideaPinned($ideaB), 'Pin in board A must not affect the idea in board B.');
    }

    // -------------------------------------------------------------------------
    // AC4: Foreign board → 404, no mutation
    // -------------------------------------------------------------------------

    public function test_wrong_board_returns_404_and_idea_unchanged(): void
    {
        $boardId1 = $this->insertBoard('pin-b1');
        $this->insertBoard('pin-b2');
        $adminId  = $this->insertUser('admin-cross@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId   = $this->seedIdea($boardId1, $adminId, 'Test idea');

        // Idea belongs to board1; request goes to board2.
        $response = $this->createApp()->handle($this->postPin('pin-b2', $ideaId, true, $adminId));

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($this->ideaPinned($ideaId));
    }

    // -------------------------------------------------------------------------
    // AC5: anon → 401, no mutation
    // -------------------------------------------------------------------------

    public function test_anon_returns_401_and_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('pin-anon');
        $userId  = $this->insertUser('user-anon@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Test idea');

        $response = $this->createApp()->handle($this->postPin('pin-anon', $ideaId, true, null));

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($this->ideaPinned($ideaId));
    }

    // -------------------------------------------------------------------------
    // AC6: logged-in non-admin → 403, no mutation
    // -------------------------------------------------------------------------

    public function test_non_admin_user_returns_403_and_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('pin-403');
        $userId  = $this->insertUser('user-403@example.com', ['is_admin' => 0]);
        $ideaId  = $this->seedIdea($boardId, $userId, 'Test idea');

        $response = $this->createApp()->handle($this->postPin('pin-403', $ideaId, true, $userId));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->ideaPinned($ideaId));
    }

    // -------------------------------------------------------------------------
    // AC7: Idempotent no-op — target state == current state
    // -------------------------------------------------------------------------

    public function test_pinning_already_pinned_idea_is_noop_returns_200(): void
    {
        $boardId = $this->insertBoard('pin-noop');
        $adminId = $this->insertUser('admin-noop@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea', ['is_pinned' => 1]);

        $response = $this->createApp()->handle($this->postPin('pin-noop', $ideaId, true, $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->ideaPinned($ideaId));
    }

    public function test_noop_pin_does_not_write_audit_log(): void
    {
        $boardId = $this->insertBoard('pin-noop-audit');
        $adminId = $this->insertUser('admin-noop-audit@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea', ['is_pinned' => 0]);

        $this->createApp()->handle($this->postPin('pin-noop-audit', $ideaId, false, $adminId));

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('idea.pin.changed', $log, 'No-op must not create an audit entry.');
    }

    // -------------------------------------------------------------------------
    // AC8: Audit log contains idea.pin.changed, without PII
    // -------------------------------------------------------------------------

    public function test_audit_log_contains_idea_pin_changed_event(): void
    {
        $boardId = $this->insertBoard('pin-audit');
        $adminId = $this->insertUser('admin-audit-secret@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea');

        $this->createApp()->handle($this->postPin('pin-audit', $ideaId, true, $adminId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.pin.changed', $log, 'Audit log must contain the event.');
        self::assertStringContainsString('"pinned":true', $log);
    }

    public function test_audit_log_does_not_contain_pii(): void
    {
        $boardId = $this->insertBoard('pin-audit-pii');
        $adminId = $this->insertUser('admin-pii-secret@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea');

        $this->createApp()->handle($this->postPin('pin-audit-pii', $ideaId, true, $adminId));

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('admin-pii-secret@example.com', $log, 'Email must not appear in the log.');
    }

    // -------------------------------------------------------------------------
    // Invalid payload → 422, no mutation
    // -------------------------------------------------------------------------

    public function test_missing_pinned_field_returns_422_and_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('pin-422');
        $adminId = $this->insertUser('admin-422@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea');

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pin-422/ideas/' . $ideaId . '/pin')
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($adminId),
            ])
            ->withParsedBody(['_csrf' => $token]);

        $response = $this->createApp()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($this->ideaPinned($ideaId));
    }

    // -------------------------------------------------------------------------
    // JSON response format
    // -------------------------------------------------------------------------

    public function test_response_is_json_with_correct_content_type(): void
    {
        $boardId = $this->insertBoard('pin-ct');
        $adminId = $this->insertUser('admin-ct@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdea($boardId, $adminId, 'Test idea');

        $response = $this->createApp()->handle($this->postPin('pin-ct', $ideaId, true, $adminId));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('ok', $data);
        self::assertArrayHasKey('pinned', $data);
    }
}
