<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /{board}/ideas/{id}/status.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create),
 * the identical pipeline to production: Session → AuthN → AuthZ admin → BlockCheck →
 * CSRF → RateLimit perAction.
 *
 * AC coverage:
 *   AC1: Admin sets status via a valid transition → 200, value + updated_at persisted.
 *   AC2: Invalid transition / invalid target status → 422, idea unchanged.
 *   AC3: anon → 401, non-admin → 403, foreign board → 404, admin → 200.
 *   AC4: Audit log contains idea.status.changed with from→to, without PII.
 */
final class IdeaStatusActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postStatus(
        string $slug,
        int $ideaId,
        string $status,
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
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/status')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'status' => $status]);
    }

    // -------------------------------------------------------------------------
    // Helper: create an idea with a given status directly via the admin user.
    // -------------------------------------------------------------------------

    private function seedIdeaWithStatus(int $boardId, int $authorId, string $status): int
    {
        return $this->seedIdea($boardId, $authorId, 'Test idea', ['status' => $status]);
    }

    private function ideaStatus(int $ideaId): string
    {
        return (string) $this->conn->fetchOne('SELECT status FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    private function ideaUpdatedAt(int $ideaId): string
    {
        return (string) $this->conn->fetchOne('SELECT updated_at FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    // -------------------------------------------------------------------------
    // AC1: Admin sets status via a valid transition → 200, persisted
    // -------------------------------------------------------------------------

    public function test_admin_valid_transition_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-ok');
        $adminId = $this->insertUser('admin@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-ok', $ideaId, 'planned', $adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertSame('planned', $data['status'] ?? null);
        self::assertSame('planned', $this->ideaStatus($ideaId), 'Status must be persisted in the DB.');
    }

    public function test_admin_planned_to_in_progress_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-p2i');
        $adminId = $this->insertUser('admin-p2i@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'planned');

        $response = $this->createApp()->handle($this->postStatus('status-p2i', $ideaId, 'in_progress', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('in_progress', $this->ideaStatus($ideaId));
    }

    public function test_admin_in_progress_to_done_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-i2d');
        $adminId = $this->insertUser('admin-i2d@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'in_progress');

        $response = $this->createApp()->handle($this->postStatus('status-i2d', $ideaId, 'done', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('done', $this->ideaStatus($ideaId));
    }

    public function test_admin_open_to_declined_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-decl');
        $adminId = $this->insertUser('admin-decl@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-decl', $ideaId, 'declined', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('declined', $this->ideaStatus($ideaId));
    }

    public function test_updated_at_changes_after_status_mutation(): void
    {
        $boardId     = $this->insertBoard('status-ts');
        $adminId     = $this->insertUser('admin-ts@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $oldTimestamp = '2020-01-01 00:00:00';
        $ideaId      = $this->seedIdea($boardId, $adminId, 'Timestamp idea', [
            'status'     => 'open',
            'updated_at' => $oldTimestamp,
        ]);

        $this->createApp()->handle($this->postStatus('status-ts', $ideaId, 'planned', $adminId));

        self::assertNotSame($oldTimestamp, $this->ideaUpdatedAt($ideaId), 'updated_at must have changed.');
    }

    // -------------------------------------------------------------------------
    // AC2: Invalid transition / invalid status → 422, idea unchanged
    // -------------------------------------------------------------------------

    public function test_invalid_target_status_returns_422_and_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('status-422a');
        $adminId = $this->insertUser('admin-422a@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-422a', $ideaId, 'flying', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('open', $this->ideaStatus($ideaId), 'Status must not have changed.');
    }

    public function test_invalid_transition_declined_to_done_returns_422(): void
    {
        $boardId = $this->insertBoard('status-422b');
        $adminId = $this->insertUser('admin-422b@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'declined');

        $response = $this->createApp()->handle($this->postStatus('status-422b', $ideaId, 'done', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('declined', $this->ideaStatus($ideaId), 'Status must not have changed.');
    }

    public function test_invalid_transition_done_to_open_returns_422(): void
    {
        $boardId = $this->insertBoard('status-422c');
        $adminId = $this->insertUser('admin-422c@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'done');

        $response = $this->createApp()->handle($this->postStatus('status-422c', $ideaId, 'open', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('done', $this->ideaStatus($ideaId));
    }

    public function test_invalid_transition_declined_to_in_progress_returns_422(): void
    {
        $boardId = $this->insertBoard('status-422d');
        $adminId = $this->insertUser('admin-422d@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'declined');

        $response = $this->createApp()->handle($this->postStatus('status-422d', $ideaId, 'in_progress', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('declined', $this->ideaStatus($ideaId));
    }

    // -------------------------------------------------------------------------
    // AC3: AuthZ — anon → 401, non-admin → 403, foreign board → 404, admin → 200
    // -------------------------------------------------------------------------

    public function test_anon_returns_401_and_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('status-anon');
        $userId  = $this->insertUser('user-anon@example.com');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $userId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-anon', $ideaId, 'planned', null));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('open', $this->ideaStatus($ideaId));
    }

    public function test_non_admin_user_returns_403_and_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('status-403');
        $userId  = $this->insertUser('user-403@example.com', ['is_admin' => 0]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $userId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-403', $ideaId, 'planned', $userId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('open', $this->ideaStatus($ideaId));
    }

    public function test_wrong_board_returns_404_and_idea_unchanged(): void
    {
        $boardId1 = $this->insertBoard('status-b1');
        $this->insertBoard('status-b2');
        $adminId  = $this->insertUser('admin-cross@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId   = $this->seedIdeaWithStatus($boardId1, $adminId, 'open');

        // Idea belongs to board1; request goes to board2.
        $response = $this->createApp()->handle($this->postStatus('status-b2', $ideaId, 'planned', $adminId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('open', $this->ideaStatus($ideaId));
    }

    // -------------------------------------------------------------------------
    // Idempotent no-op: self→self → 200, no audit entry
    // -------------------------------------------------------------------------

    public function test_self_transition_is_noop_returns_200_and_status_unchanged(): void
    {
        $boardId = $this->insertBoard('status-noop');
        $adminId = $this->insertUser('admin-noop@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'planned');

        $response = $this->createApp()->handle($this->postStatus('status-noop', $ideaId, 'planned', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_self_transition_does_not_write_audit_log(): void
    {
        $boardId = $this->insertBoard('status-noop-audit');
        $adminId = $this->insertUser('admin-noop-audit@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $this->createApp()->handle($this->postStatus('status-noop-audit', $ideaId, 'open', $adminId));

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('idea.status.changed', $log, 'No-op must not create an audit entry.');
    }

    // -------------------------------------------------------------------------
    // AC4: Audit log contains idea.status.changed with from→to, PII-masked
    // -------------------------------------------------------------------------

    public function test_audit_log_contains_idea_status_changed_event(): void
    {
        $boardId = $this->insertBoard('status-audit');
        $adminId = $this->insertUser('admin-audit-secret@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $this->createApp()->handle($this->postStatus('status-audit', $ideaId, 'planned', $adminId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.status.changed', $log, 'Audit log must contain the event.');
    }

    public function test_audit_log_contains_from_and_to_fields(): void
    {
        $boardId = $this->insertBoard('status-audit-ft');
        $adminId = $this->insertUser('admin-ft@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $this->createApp()->handle($this->postStatus('status-audit-ft', $ideaId, 'planned', $adminId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('"status_from":"open"', $log);
        self::assertStringContainsString('"status_to":"planned"', $log);
    }

    public function test_audit_log_does_not_contain_pii(): void
    {
        $boardId = $this->insertBoard('status-audit-pii');
        $adminId = $this->insertUser('admin-pii-secret@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $this->createApp()->handle($this->postStatus('status-audit-pii', $ideaId, 'planned', $adminId));

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('admin-pii-secret@example.com', $log, 'Email must not appear in the log.');
    }

    // -------------------------------------------------------------------------
    // JSON response format
    // -------------------------------------------------------------------------

    public function test_response_is_json_with_correct_content_type(): void
    {
        $boardId = $this->insertBoard('status-ct');
        $adminId = $this->insertUser('admin-ct@example.com', ['is_admin' => 1]);
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-ct', $ideaId, 'planned', $adminId));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('ok', $data);
        self::assertArrayHasKey('status', $data);
    }
}
