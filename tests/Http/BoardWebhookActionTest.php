<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the board-webhooks admin endpoints: AuthZ,
 * cross-tenant isolation, the SSRF guard rejecting private targets at save
 * time (422), the secret being returned exactly once, and pause/delete.
 *
 * Uses IP-literal URLs throughout (no hostname) so the guard's IP-range
 * check runs without any DNS resolution — deterministic, no network access,
 * same approach as SmtpHostPolicyTest's IP-literal coverage.
 */
final class BoardWebhookActionTest extends IntegrationTestCase
{
    private function sessions(): \Votepit\Security\SessionService
    {
        return new \Votepit\Security\SessionService(str_repeat('a', 64), 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function getRequest(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }
        return $request;
    }

    /** @param array<string, mixed> $body */
    private function mutatingRequest(string $method, string $path, ?int $userId, array $body, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();
        $cookies   = [];

        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');

        if ($withCsrf) {
            $request = $request->withHeader('X-CSRF-Token', $csrfToken);
        }

        return $request->withParsedBody($body);
    }

    private function makeOwner(string $email): int
    {
        $ownerId = $this->insertUser($email);
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        return $ownerId;
    }

    // ── AuthZ ─────────────────────────────────────────────────────────────

    public function test_get_webhook_as_anon_is_rejected(): void
    {
        $this->insertBoard('demo');
        $response = $this->createApp()->handle($this->getRequest('/admin/boards/demo/webhook', null));
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_webhook_as_non_admin_is_rejected(): void
    {
        $this->insertBoard('demo');
        $userId = $this->insertUser('plain@example.com');
        $response = $this->createApp()->handle($this->getRequest('/admin/boards/demo/webhook', $userId));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_put_webhook_without_csrf_is_rejected(): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner-csrf@example.com');
        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook'], withCsrf: false),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    // ── SSRF guard at save time ──────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/hook'];
        yield 'private 10/8' => ['https://10.0.0.5/hook'];
        yield 'link-local/metadata' => ['https://169.254.169.254/hook'];
        yield 'http scheme' => ['http://93.184.216.34/hook'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedUrls')]
    public function test_put_webhook_rejects_ssrf_unsafe_urls(string $url): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner-ssrf@example.com');

        $response = $this->createApp()->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => $url]),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('url', $data['error']['fields'] ?? []);

        // Nothing gets persisted on a rejected save.
        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_webhooks'));
    }

    // ── Save / secret handling ───────────────────────────────────────────

    public function test_owner_saves_webhook_and_receives_the_secret_exactly_once(): void
    {
        $boardId = $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner-save@example.com');
        $app     = $this->createApp();

        $putResponse = $app->handle(
            $this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']),
        );
        self::assertSame(200, $putResponse->getStatusCode());
        $putData = json_decode((string) $putResponse->getBody(), true);
        self::assertTrue($putData['ok'] ?? false);
        self::assertIsString($putData['secret'] ?? null);
        self::assertSame(64, strlen($putData['secret']));

        $storedEncrypted = (string) $this->conn->fetchOne('SELECT secret_encrypted FROM board_webhooks WHERE board_id = :id', ['id' => $boardId]);
        self::assertNotSame($putData['secret'], $storedEncrypted, 'the secret must never be stored in plaintext');

        $getResponse = $app->handle($this->getRequest('/admin/boards/demo/webhook', $ownerId));
        self::assertSame(200, $getResponse->getStatusCode());
        $getData = json_decode((string) $getResponse->getBody(), true);
        self::assertTrue($getData['configured']);
        self::assertSame('https://93.184.216.34/hook', $getData['url']);
        self::assertArrayNotHasKey('secret', $getData, 'GET must never return the secret again');
    }

    public function test_saving_again_rotates_the_secret(): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner-rotate@example.com');
        $app     = $this->createApp();

        $first  = $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));
        $second = $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));

        $firstSecret  = json_decode((string) $first->getBody(), true)['secret'];
        $secondSecret = json_decode((string) $second->getBody(), true)['secret'];
        self::assertNotSame($firstSecret, $secondSecret);
    }

    // ── Pause / resume / delete ───────────────────────────────────────────

    public function test_patch_pauses_and_resumes_without_rotating_secret(): void
    {
        $boardId = $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner-pause@example.com');
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));

        $pauseResponse = $app->handle($this->mutatingRequest('PATCH', '/admin/boards/demo/webhook', $ownerId, ['active' => false]));
        self::assertSame(200, $pauseResponse->getStatusCode());

        $active = (int) $this->conn->fetchOne('SELECT active FROM board_webhooks WHERE board_id = :id', ['id' => $boardId]);
        self::assertSame(0, $active);

        $resumeResponse = $app->handle($this->mutatingRequest('PATCH', '/admin/boards/demo/webhook', $ownerId, ['active' => true]));
        self::assertSame(200, $resumeResponse->getStatusCode());
        $active = (int) $this->conn->fetchOne('SELECT active FROM board_webhooks WHERE board_id = :id', ['id' => $boardId]);
        self::assertSame(1, $active);
    }

    public function test_delete_removes_the_configuration(): void
    {
        $boardId = $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner-delete@example.com');
        $app     = $this->createApp();

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));

        $deleteResponse = $app->handle($this->mutatingRequest('DELETE', '/admin/boards/demo/webhook', $ownerId, []));
        self::assertSame(200, $deleteResponse->getStatusCode());

        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_webhooks WHERE board_id = :id', ['id' => $boardId]));
    }

    // ── Cross-tenant isolation ────────────────────────────────────────────

    public function test_owner_cannot_configure_or_see_a_foreign_boards_webhook(): void
    {
        $foreignAccount = $this->insertAccount(['slug' => 'acct-webhook-foreign', 'name' => 'Foreign Account']);
        $this->insertBoard('foreign-board', ['account_id' => $foreignAccount]);

        $ownerA = $this->makeOwner('owner-foreign-webhook@example.com');
        $app    = $this->createApp();

        $getResponse = $app->handle($this->getRequest('/admin/boards/foreign-board/webhook', $ownerA));
        self::assertSame(404, $getResponse->getStatusCode());

        $putResponse = $app->handle(
            $this->mutatingRequest('PUT', '/admin/boards/foreign-board/webhook', $ownerA, ['url' => 'https://93.184.216.34/hook']),
        );
        self::assertSame(404, $putResponse->getStatusCode());

        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_webhooks'));
    }
}
