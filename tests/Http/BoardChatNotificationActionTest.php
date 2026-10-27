<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Slack/Discord chat-notification admin endpoints: AuthZ, plan gate,
 * host-allowlist validation, the URL never being returned, encryption at
 * rest, and cross-tenant isolation (self-host and cloud routing).
 */
final class BoardChatNotificationActionTest extends IntegrationTestCase
{
    private const SLACK_URL   = 'https://hooks.slack.com/services/T0001/B0002/supersecrettoken';
    private const DISCORD_URL = 'https://discord.com/api/webhooks/123456/super_secret-token';

    private function getRequest(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => (new SessionService(str_repeat('a', 64), 3600, false))->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }
        return $request;
    }

    /** @param array<string, mixed> $body */
    private function mutatingRequest(string $method, string $path, int $userId, array $body = []): ServerRequestInterface
    {
        $csrf      = new CsrfService(str_repeat('a', 64), 3600, false);
        $csrfToken = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams([
                'votepit_sess' => (new SessionService(str_repeat('a', 64), 3600, false))->sign(['uid' => $userId, 'v' => 0]),
                'votepit_csrf' => $csrf->sign($csrfToken),
            ])
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $csrfToken)
            ->withParsedBody($body);
    }

    private function makeOwner(string $email, ?int $accountId = null): int
    {
        $ownerId = $this->insertUser($email);
        $this->insertAccountMember($accountId ?? $this->defaultAccountId(), $ownerId, 'owner');
        return $ownerId;
    }

    /** @return array<string, mixed> */
    private function body(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function test_anon_and_non_admin_are_rejected(): void
    {
        $this->insertBoard('demo');
        $app = $this->createApp();
        self::assertSame(401, $app->handle($this->getRequest('/admin/boards/demo/chat-notifications', null))->getStatusCode());

        $userId = $this->insertUser('plain@example.com');
        self::assertSame(403, $app->handle($this->getRequest('/admin/boards/demo/chat-notifications', $userId))->getStatusCode());
        self::assertSame(403, $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/slack', $userId, ['url' => self::SLACK_URL]))->getStatusCode());
    }

    public function test_owner_saves_both_providers_and_never_gets_the_url_back(): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner@example.com');
        $app     = $this->createApp();

        $put = $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/slack', $ownerId, ['url' => self::SLACK_URL]));
        self::assertSame(200, $put->getStatusCode());
        self::assertStringNotContainsString('supersecrettoken', (string) $put->getBody());

        $put = $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/discord', $ownerId, ['url' => self::DISCORD_URL]));
        self::assertSame(200, $put->getStatusCode());

        $get = $app->handle($this->getRequest('/admin/boards/demo/chat-notifications', $ownerId));
        self::assertSame(200, $get->getStatusCode());
        $raw = (string) $get->getBody();
        self::assertStringNotContainsString('supersecret', $raw);
        self::assertStringNotContainsString('super_secret', $raw);
        $data = $this->body($get);
        self::assertTrue($data['allowed']);
        self::assertSame('hooks.slack.com/…oken', $data['integrations']['slack']['url_hint']);
        self::assertSame('discord.com/…oken', $data['integrations']['discord']['url_hint']);

        // Encrypted at rest, own key-separation context.
        $stored = (string) $this->conn->fetchOne("SELECT url_encrypted FROM board_chat_integrations WHERE provider = 'slack'");
        self::assertStringNotContainsString('hooks.slack.com', $stored);
        self::assertSame(self::SLACK_URL, (new EncryptionService(str_repeat('a', 64), 'board_chat_webhook'))->decrypt($stored));
        self::assertNull((new EncryptionService(str_repeat('a', 64), 'board_webhook'))->decrypt($stored));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidUrls(): iterable
    {
        yield 'arbitrary host'        => ['slack', 'https://93.184.216.34/hook'];
        yield 'internal host'         => ['slack', 'https://localhost/services/a/b/c'];
        yield 'discord url for slack' => ['slack', self::DISCORD_URL];
        yield 'slack url for discord' => ['discord', self::SLACK_URL];
        yield 'http'                  => ['slack', 'http://hooks.slack.com/services/T1/B2/abc'];
        yield 'lookalike'             => ['discord', 'https://discord.com.evil.example/api/webhooks/1/abc'];
        yield 'empty'                 => ['discord', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUrls')]
    public function test_rejects_urls_outside_the_provider_allowlist(string $provider, string $url): void
    {
        $this->insertBoard('demo');
        $ownerId  = $this->makeOwner('owner@example.com');
        $response = $this->createApp()->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/' . $provider, $ownerId, ['url' => $url]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $this->body($response)['error']['key']);
        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_chat_integrations'));
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->insertBoard('demo');
        $ownerId  = $this->makeOwner('owner@example.com');
        $response = $this->createApp()->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/teams', $ownerId, ['url' => self::SLACK_URL]));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_plan_without_chat_notifications_gets_422_but_may_still_delete(): void
    {
        $boardId = $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner@example.com');
        $app     = $this->createApp();
        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/slack', $ownerId, ['url' => self::SLACK_URL]));

        $this->setAccountPlan($this->defaultAccountId(), 'free');

        $get = $this->body($app->handle($this->getRequest('/admin/boards/demo/chat-notifications', $ownerId)));
        self::assertFalse($get['allowed']);
        self::assertNotNull($get['integrations']['slack'], 'existing config stays visible after a downgrade');

        $put = $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/discord', $ownerId, ['url' => self::DISCORD_URL]));
        self::assertSame(422, $put->getStatusCode());
        self::assertSame('plan_limit_chat_notifications', $this->body($put)['error']['key']);

        $delete = $app->handle($this->mutatingRequest('DELETE', '/admin/boards/demo/chat-notifications/slack', $ownerId));
        self::assertSame(200, $delete->getStatusCode());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_chat_integrations WHERE board_id = ?', [$boardId]));
    }

    public function test_delete_of_missing_integration_is_404(): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->makeOwner('owner@example.com');
        $response = $this->createApp()->handle($this->mutatingRequest('DELETE', '/admin/boards/demo/chat-notifications/slack', $ownerId));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_owner_cannot_touch_a_foreign_accounts_board(): void
    {
        $foreignAccount = $this->insertAccount(['slug' => 'acct-chat-foreign']);
        $foreignBoard   = $this->insertBoard('foreign-board', ['account_id' => $foreignAccount]);
        $this->conn->insert('board_chat_integrations', [
            'board_id' => $foreignBoard, 'provider' => 'slack', 'url_encrypted' => 'x', 'url_hint' => 'foreign-hint',
        ]);

        $ownerA = $this->makeOwner('owner-a@example.com');
        $app    = $this->createApp();

        self::assertSame(404, $app->handle($this->getRequest('/admin/boards/foreign-board/chat-notifications', $ownerA))->getStatusCode());
        self::assertSame(404, $app->handle($this->mutatingRequest('PUT', '/admin/boards/foreign-board/chat-notifications/slack', $ownerA, ['url' => self::SLACK_URL]))->getStatusCode());
        self::assertSame(404, $app->handle($this->mutatingRequest('DELETE', '/admin/boards/foreign-board/chat-notifications/slack', $ownerA))->getStatusCode());
        self::assertSame('x', $this->conn->fetchOne('SELECT url_encrypted FROM board_chat_integrations WHERE board_id = ?', [$foreignBoard]));
    }

    public function test_cloud_routing_owner_of_account_a_cannot_reach_account_b(): void
    {
        $accountA = $this->insertAccount(['slug' => 'alpha', 'plan' => 'pro']);
        $accountB = $this->insertAccount(['slug' => 'bravo', 'plan' => 'pro']);
        $this->insertBoard('board-a', ['account_id' => $accountA]);
        $this->insertBoard('board-b', ['account_id' => $accountB]);
        $ownerA = $this->makeOwner('owner-alpha@example.com', $accountA);

        $app = $this->createApp(config: Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));

        self::assertSame(200, $app->handle($this->getRequest('/alpha/admin/boards/board-a/chat-notifications', $ownerA))->getStatusCode());
        self::assertContains(
            $app->handle($this->getRequest('/bravo/admin/boards/board-b/chat-notifications', $ownerA))->getStatusCode(),
            [403, 404],
        );
        self::assertContains(
            $app->handle($this->mutatingRequest('PUT', '/bravo/admin/boards/board-b/chat-notifications/slack', $ownerA, ['url' => self::SLACK_URL]))->getStatusCode(),
            [403, 404],
        );
        self::assertSame(404, $app->handle($this->getRequest('/alpha/admin/boards/board-b/chat-notifications', $ownerA))->getStatusCode());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_chat_integrations'));
    }
}
