<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for /operator/boards/* (Operator panel).
 *
 * AC coverage:
 *   - Operator can lock/unlock/delete ANY board, regardless of which account
 *     owns it (incl. boards belonging to the default account).
 *   - A locked board stops being publicly reachable via
 *     BoardRepository::findPublicBySlugForAccount() (the confirm/visibility
 *     chokepoint, extended here) without touching visibility.
 *   - Every mutation produces an audit-log entry tagged actor_tier=operator.
 */
final class OperatorBoardActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function post(string $path, int $operatorId): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($operatorId),
                $csrf->cookieName() => $csrf->sign($token),
            ])
            ->withParsedBody(['_csrf' => $token]);
    }

    private function operator(): int
    {
        return $this->insertUser('operator-board@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_operator_locks_and_unlocks_a_default_account_board(): void
    {
        $boardId    = $this->insertBoard('op-lock-board');
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $boardRepo = new \Votepit\Persistence\BoardRepository($this->conn);
        self::assertIsArray($boardRepo->findPublicBySlugForAccount('op-lock-board', $this->defaultAccountId()));

        $response = $app->handle($this->post("/operator/boards/{$boardId}/lock", $operatorId));
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($boardRepo->findPublicBySlugForAccount('op-lock-board', $this->defaultAccountId()));

        // Visibility itself is untouched — this is a distinct mechanism.
        $visibility = $this->conn->fetchOne('SELECT visibility FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertSame('public', $visibility);

        $response = $app->handle($this->post("/operator/boards/{$boardId}/unlock", $operatorId));
        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($boardRepo->findPublicBySlugForAccount('op-lock-board', $this->defaultAccountId()));
    }

    public function test_operator_deletes_a_foreign_account_board(): void
    {
        $accountId  = $this->insertAccount();
        $boardId    = $this->insertBoard('op-delete-board', ['account_id' => $accountId]);
        $ideaId     = $this->seedIdea($boardId, $this->insertUser('author-op-del@example.com'));
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->post("/operator/boards/{$boardId}/delete", $operatorId));
        self::assertSame(200, $response->getStatusCode());

        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM boards WHERE id = :id', ['id' => $boardId]));
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaId]));
        // The owning account itself is untouched.
        self::assertIsArray($this->conn->fetchAssociative('SELECT id FROM accounts WHERE id = :id', ['id' => $accountId]));
    }

    public function test_unknown_board_id_returns_404(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->post('/operator/boards/999999/lock', $operatorId));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_every_operator_board_action_is_audit_logged(): void
    {
        $boardId    = $this->insertBoard('op-audit-board');
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $app->handle($this->post("/operator/boards/{$boardId}/lock", $operatorId));
        $app->handle($this->post("/operator/boards/{$boardId}/unlock", $operatorId));
        $app->handle($this->post("/operator/boards/{$boardId}/delete", $operatorId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('operator.board.locked', $log);
        self::assertStringContainsString('operator.board.unlocked', $log);
        self::assertStringContainsString('operator.board.deleted', $log);
        self::assertStringContainsString('"actor_tier":"operator"', $log);
    }
}
