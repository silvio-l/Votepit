<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für POST /{board}/ideas/{id}/status (Sprint 10, Issue 01).
 *
 * Alle Assertions laufen ausschließlich durch den HTTP-Seam (AppFactory::create),
 * identische Pipeline wie Produktion: Session → AuthN → AuthZ admin → BlockCheck →
 * CSRF → RateLimit perAction.
 *
 * AC-Abdeckung:
 *   AC1: Admin setzt Status über gültigen Übergang → 200, Wert + updated_at persistiert.
 *   AC2: Ungültiger Übergang / ungültiger Zielstatus → 422, Idee unverändert.
 *   AC3: anon → 401, non-admin → 403, fremdes Board → 404, Admin → 200.
 *   AC4: Audit-Log enthält idea.status.changed mit from→to, ohne PII.
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
    // Helfer: Idee mit gegebenem Status direkt per Admin-User anlegen.
    // -------------------------------------------------------------------------

    private function seedIdeaWithStatus(int $boardId, int $authorId, string $status): int
    {
        return $this->seedIdea($boardId, $authorId, 'Test-Idee', ['status' => $status]);
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
    // AC1: Admin setzt Status über gültigen Übergang → 200, persistiert
    // -------------------------------------------------------------------------

    public function test_admin_valid_transition_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-ok');
        $adminId = $this->insertUser('admin@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-ok', $ideaId, 'planned', $adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertSame('planned', $data['status'] ?? null);
        self::assertSame('planned', $this->ideaStatus($ideaId), 'Status muss in DB persistiert sein.');
    }

    public function test_admin_planned_to_in_progress_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-p2i');
        $adminId = $this->insertUser('admin-p2i@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'planned');

        $response = $this->createApp()->handle($this->postStatus('status-p2i', $ideaId, 'in_progress', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('in_progress', $this->ideaStatus($ideaId));
    }

    public function test_admin_in_progress_to_done_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-i2d');
        $adminId = $this->insertUser('admin-i2d@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'in_progress');

        $response = $this->createApp()->handle($this->postStatus('status-i2d', $ideaId, 'done', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('done', $this->ideaStatus($ideaId));
    }

    public function test_admin_open_to_declined_returns_200_and_persists(): void
    {
        $boardId = $this->insertBoard('status-decl');
        $adminId = $this->insertUser('admin-decl@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-decl', $ideaId, 'declined', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('declined', $this->ideaStatus($ideaId));
    }

    public function test_updated_at_changes_after_status_mutation(): void
    {
        $boardId     = $this->insertBoard('status-ts');
        $adminId     = $this->insertUser('admin-ts@example.com', ['is_admin' => 1]);
        $oldTimestamp = '2020-01-01 00:00:00';
        $ideaId      = $this->seedIdea($boardId, $adminId, 'Zeitstempel-Idee', [
            'status'     => 'open',
            'updated_at' => $oldTimestamp,
        ]);

        $this->createApp()->handle($this->postStatus('status-ts', $ideaId, 'planned', $adminId));

        self::assertNotSame($oldTimestamp, $this->ideaUpdatedAt($ideaId), 'updated_at muss sich geändert haben.');
    }

    // -------------------------------------------------------------------------
    // AC2: Ungültiger Übergang / ungültiger Status → 422, Idee unverändert
    // -------------------------------------------------------------------------

    public function test_invalid_target_status_returns_422_and_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('status-422a');
        $adminId = $this->insertUser('admin-422a@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-422a', $ideaId, 'flying', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('open', $this->ideaStatus($ideaId), 'Status darf sich nicht geändert haben.');
    }

    public function test_invalid_transition_declined_to_done_returns_422(): void
    {
        $boardId = $this->insertBoard('status-422b');
        $adminId = $this->insertUser('admin-422b@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'declined');

        $response = $this->createApp()->handle($this->postStatus('status-422b', $ideaId, 'done', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('declined', $this->ideaStatus($ideaId), 'Status darf sich nicht geändert haben.');
    }

    public function test_invalid_transition_done_to_open_returns_422(): void
    {
        $boardId = $this->insertBoard('status-422c');
        $adminId = $this->insertUser('admin-422c@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'done');

        $response = $this->createApp()->handle($this->postStatus('status-422c', $ideaId, 'open', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('done', $this->ideaStatus($ideaId));
    }

    public function test_invalid_transition_declined_to_in_progress_returns_422(): void
    {
        $boardId = $this->insertBoard('status-422d');
        $adminId = $this->insertUser('admin-422d@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'declined');

        $response = $this->createApp()->handle($this->postStatus('status-422d', $ideaId, 'in_progress', $adminId));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('declined', $this->ideaStatus($ideaId));
    }

    // -------------------------------------------------------------------------
    // AC3: AuthZ — anon → 401, non-admin → 403, fremdes Board → 404, admin → 200
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
        $ideaId   = $this->seedIdeaWithStatus($boardId1, $adminId, 'open');

        // Idee gehört zu Board1; Request geht an Board2.
        $response = $this->createApp()->handle($this->postStatus('status-b2', $ideaId, 'planned', $adminId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('open', $this->ideaStatus($ideaId));
    }

    // -------------------------------------------------------------------------
    // Idempotenter No-op: Selbst→Selbst → 200, kein Audit-Eintrag
    // -------------------------------------------------------------------------

    public function test_self_transition_is_noop_returns_200_and_status_unchanged(): void
    {
        $boardId = $this->insertBoard('status-noop');
        $adminId = $this->insertUser('admin-noop@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'planned');

        $response = $this->createApp()->handle($this->postStatus('status-noop', $ideaId, 'planned', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_self_transition_does_not_write_audit_log(): void
    {
        $boardId = $this->insertBoard('status-noop-audit');
        $adminId = $this->insertUser('admin-noop-audit@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $this->createApp()->handle($this->postStatus('status-noop-audit', $ideaId, 'open', $adminId));

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('idea.status.changed', $log, 'No-op darf keinen Audit-Eintrag erzeugen.');
    }

    // -------------------------------------------------------------------------
    // AC4: Audit-Log enthält idea.status.changed mit from→to, PII-maskiert
    // -------------------------------------------------------------------------

    public function test_audit_log_contains_idea_status_changed_event(): void
    {
        $boardId = $this->insertBoard('status-audit');
        $adminId = $this->insertUser('admin-audit-secret@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $this->createApp()->handle($this->postStatus('status-audit', $ideaId, 'planned', $adminId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.status.changed', $log, 'Audit-Log muss den Event enthalten.');
    }

    public function test_audit_log_contains_from_and_to_fields(): void
    {
        $boardId = $this->insertBoard('status-audit-ft');
        $adminId = $this->insertUser('admin-ft@example.com', ['is_admin' => 1]);
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
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $this->createApp()->handle($this->postStatus('status-audit-pii', $ideaId, 'planned', $adminId));

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('admin-pii-secret@example.com', $log, 'E-Mail darf nicht im Log stehen.');
    }

    // -------------------------------------------------------------------------
    // JSON-Antwort-Format
    // -------------------------------------------------------------------------

    public function test_response_is_json_with_correct_content_type(): void
    {
        $boardId = $this->insertBoard('status-ct');
        $adminId = $this->insertUser('admin-ct@example.com', ['is_admin' => 1]);
        $ideaId  = $this->seedIdeaWithStatus($boardId, $adminId, 'open');

        $response = $this->createApp()->handle($this->postStatus('status-ct', $ideaId, 'planned', $adminId));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('ok', $data);
        self::assertArrayHasKey('status', $data);
    }
}
