<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for /operator/accounts/* (Operator panel).
 *
 * AC coverage:
 *   - Operator can lock/unlock/delete ANY account, regardless of ownership.
 *   - A locked account's boards stop being publicly reachable (extends the
 *     confirm/visibility chokepoint, BoardVisibilityGateTest pattern).
 *   - Every mutation produces an audit-log entry tagged actor_tier=operator.
 */
final class OperatorAccountActionTest extends IntegrationTestCase
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
        return $this->insertUser('operator-acc@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_list_presents_is_default_as_a_real_json_boolean(): void
    {
        // Regression test: listAllForOperator() returns the raw DB row,
        // where is_default is an integer 0/1, not a real boolean — the
        // frontend's `{a.is_default && (...)}` badge check used to render
        // the literal integer 0 as visible text for every non-default
        // account (JS `0 && x` short-circuits to `0`). OperatorAccountAction
        // must present real JSON booleans, not raw column values.
        $accountId  = $this->insertAccount();
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle((new ServerRequestFactory())
            ->createServerRequest('GET', '/operator/accounts')
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($operatorId)]));

        self::assertSame(200, $response->getStatusCode());
        $data     = json_decode((string) $response->getBody(), true);
        $accounts = $data['accounts'];

        $foreign = array_values(array_filter($accounts, static fn (array $a): bool => $a['id'] === $accountId));
        self::assertCount(1, $foreign);
        self::assertFalse($foreign[0]['is_default']);

        $default = array_values(array_filter($accounts, static fn (array $a): bool => $a['is_default'] === true));
        self::assertCount(1, $default);
    }

    public function test_operator_locks_and_unlocks_a_foreign_account(): void
    {
        $foreignAccountId = $this->insertAccount();
        $operatorId       = $this->operator();
        $app              = $this->createApp();

        $response = $app->handle($this->post("/operator/accounts/{$foreignAccountId}/lock", $operatorId));
        self::assertSame(200, $response->getStatusCode());

        $locked = $this->conn->fetchOne('SELECT locked_at FROM accounts WHERE id = :id', ['id' => $foreignAccountId]);
        self::assertNotNull($locked);

        $response = $app->handle($this->post("/operator/accounts/{$foreignAccountId}/unlock", $operatorId));
        self::assertSame(200, $response->getStatusCode());

        $unlocked = $this->conn->fetchOne('SELECT locked_at FROM accounts WHERE id = :id', ['id' => $foreignAccountId]);
        self::assertNull($unlocked);
    }

    public function test_operator_locking_an_account_hides_its_boards_from_public_reads(): void
    {
        $accountId = $this->insertAccount();
        $this->insertBoard('locked-acc-board', ['account_id' => $accountId]);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $before = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/locked-acc-board'));
        // Self-host routing always resolves to the default account — this
        // board lives under a NON-default account, so it's cross-tenant-
        // unreachable via the plain slug already (404). We verify the lock's
        // effect directly at the repository chokepoint instead, which is what
        // every anon-facing route (BoardHomeAction et al.) actually calls.
        self::assertSame(404, $before->getStatusCode());

        $boardRepo = new \Votepit\Persistence\BoardRepository($this->conn);
        self::assertIsArray($boardRepo->findPublicBySlugForAccount('locked-acc-board', $accountId));

        $response = $app->handle($this->post("/operator/accounts/{$accountId}/lock", $operatorId));
        self::assertSame(200, $response->getStatusCode());

        self::assertNull($boardRepo->findPublicBySlugForAccount('locked-acc-board', $accountId));
    }

    public function test_operator_deletes_a_foreign_account_and_cascades_its_board(): void
    {
        $accountId = $this->insertAccount();
        $boardId   = $this->insertBoard('delete-acc-board', ['account_id' => $accountId]);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->post("/operator/accounts/{$accountId}/delete", $operatorId));
        self::assertSame(200, $response->getStatusCode());

        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM accounts WHERE id = :id', ['id' => $accountId]));
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM boards WHERE id = :id', ['id' => $boardId]));
    }

    public function test_locking_the_default_account_is_rejected(): void
    {
        $accounts   = new \Votepit\Persistence\AccountRepository($this->conn);
        $defaultId  = $accounts->defaultAccountId();
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->post("/operator/accounts/{$defaultId}/lock", $operatorId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('default_account_unlockable', $data['error']['key'] ?? null);
        self::assertNull($this->conn->fetchOne('SELECT locked_at FROM accounts WHERE id = :id', ['id' => $defaultId]));
    }

    public function test_deleting_the_default_account_is_rejected(): void
    {
        $accounts   = new \Votepit\Persistence\AccountRepository($this->conn);
        $defaultId  = $accounts->defaultAccountId();
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->post("/operator/accounts/{$defaultId}/delete", $operatorId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('default_account_undeletable', $data['error']['key'] ?? null);
        self::assertNotFalse($this->conn->fetchAssociative('SELECT id FROM accounts WHERE id = :id', ['id' => $defaultId]));
    }

    public function test_unknown_account_id_returns_404(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->post('/operator/accounts/999999/lock', $operatorId));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_every_operator_account_action_is_audit_logged(): void
    {
        $accountId  = $this->insertAccount();
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $app->handle($this->post("/operator/accounts/{$accountId}/lock", $operatorId));
        $app->handle($this->post("/operator/accounts/{$accountId}/unlock", $operatorId));
        $app->handle($this->post("/operator/accounts/{$accountId}/delete", $operatorId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('operator.account.locked', $log);
        self::assertStringContainsString('operator.account.unlocked', $log);
        self::assertStringContainsString('operator.account.deleted', $log);
        self::assertStringContainsString('"actor_tier":"operator"', $log);
    }
}
