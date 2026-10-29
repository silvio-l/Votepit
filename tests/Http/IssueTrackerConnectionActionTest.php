<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\IssueTracker\InMemoryIssueTrackerHttpClient;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Account-level Linear/GitHub connection (issue 19): AuthZ, plan gate,
 * provider verification before storing, token never returned/logged,
 * encryption at rest, webhook secret handling and cross-tenant isolation.
 */
final class IssueTrackerConnectionActionTest extends IntegrationTestCase
{
    private const GITHUB_TOKEN = 'test-gh-token-SUPERSECRET-VALUE';
    private const LINEAR_TOKEN = 'lin_api_SUPERSECRETLINEARKEY';
    private const LINEAR_URL   = 'https://api.linear.app/graphql';
    private const REPO_URL     = 'https://api.github.com/repos/acme/roadmap';

    private InMemoryIssueTrackerHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new InMemoryIssueTrackerHttpClient();
    }

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

    private function member(string $email, string $role = 'owner', ?int $accountId = null): int
    {
        $id = $this->insertUser($email);
        $this->insertAccountMember($accountId ?? $this->defaultAccountId(), $id, $role);
        return $id;
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, (string) $response->getBody());
        return $decoded;
    }

    /** @return \Slim\App<null> */
    private function app(?Config $config = null): \Slim\App
    {
        return $this->createApp(config: $config, issueTrackerHttpClient: $this->http);
    }

    private function queueRepo(string $fullName = 'Acme/Roadmap'): void
    {
        $this->http->queue('GET', self::REPO_URL, 200, ['full_name' => $fullName, 'has_issues' => true]);
    }

    public function test_anon_plain_user_and_moderator_are_rejected(): void
    {
        $app = $this->app();
        self::assertSame(401, $app->handle($this->getRequest('/admin/issue-trackers', null))->getStatusCode());

        $plain = $this->insertUser('plain@example.com');
        self::assertSame(403, $app->handle($this->getRequest('/admin/issue-trackers', $plain))->getStatusCode());

        $moderator = $this->member('mod@example.com', 'moderator');
        self::assertSame(403, $app->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $moderator, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap']))->getStatusCode());
        self::assertSame([], $this->http->requests);
    }

    public function test_github_connect_verifies_stores_encrypted_and_never_returns_the_token(): void
    {
        $owner = $this->member('owner@example.com');
        $app   = $this->app();
        $this->queueRepo();

        $put = $app->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap']));
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        $raw = (string) $put->getBody();
        self::assertStringNotContainsString('SUPERSECRET', $raw);
        $data = $this->body($put);
        self::assertSame('Acme/Roadmap', $data['connection']['target'], 'canonical repo name from the API');
        self::assertSame('…ALUE', $data['connection']['token_hint']);
        self::assertTrue($data['connection']['sync_enabled']);
        self::assertMatchesRegularExpression('#^http://localhost:8000/api/issue-tracker-webhooks/github/[0-9a-f]{32}$#', $data['connection']['webhook_url']);
        self::assertIsString($data['webhook_secret']);
        self::assertSame(64, strlen($data['webhook_secret']));

        // Encrypted at rest, own key-separation contexts.
        $row = $this->conn->fetchAssociative("SELECT * FROM account_issue_trackers WHERE provider = 'github'");
        self::assertIsArray($row);
        self::assertStringNotContainsString('SUPERSECRET', (string) $row['token_encrypted']);
        self::assertSame(self::GITHUB_TOKEN, (new EncryptionService(str_repeat('a', 64), 'issue_tracker_token'))->decrypt((string) $row['token_encrypted']));
        self::assertNull((new EncryptionService(str_repeat('a', 64), 'board_webhook'))->decrypt((string) $row['token_encrypted']));
        self::assertSame($data['webhook_secret'], (new EncryptionService(str_repeat('a', 64), 'issue_tracker_webhook'))->decrypt((string) $row['webhook_secret_encrypted']));

        $get = $app->handle($this->getRequest('/admin/issue-trackers', $owner));
        self::assertStringNotContainsString('SUPERSECRET', (string) $get->getBody());
        self::assertStringNotContainsString($data['webhook_secret'], (string) $get->getBody());
        $list = $this->body($get);
        self::assertTrue($list['allowed']);
        self::assertNull($list['connections']['linear']);
        self::assertSame('Acme/Roadmap', $list['connections']['github']['target']);

        // Token never written to the audit log.
        self::assertStringNotContainsString('SUPERSECRET', (string) @file_get_contents($this->logFile));
    }

    public function test_reconnect_keeps_webhook_id_and_secret_and_may_omit_the_token(): void
    {
        $owner = $this->member('owner@example.com');
        $app   = $this->app();
        $this->queueRepo();
        $first = $this->body($app->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap'])));

        $this->http->queue('GET', 'https://api.github.com/repos/acme/other', 200, ['full_name' => 'acme/other', 'has_issues' => true]);
        $second = $this->body($app->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['repository' => 'acme/other'])));

        self::assertSame($first['connection']['webhook_url'], $second['connection']['webhook_url']);
        self::assertArrayNotHasKey('webhook_secret', $second, 'secret is only shown once');
        self::assertSame('acme/other', $second['connection']['target']);
        self::assertContains('Authorization: Bearer ' . self::GITHUB_TOKEN, $this->http->requests[1]['headers'], 'stored token reused');
    }

    public function test_linear_connect_resolves_team_and_stores_the_pasted_signing_secret(): void
    {
        $owner = $this->member('owner@example.com');
        $this->http->queue('POST', self::LINEAR_URL, 200, ['data' => ['teams' => ['nodes' => [['id' => 'team-uuid', 'key' => 'ENG']]]]]);

        $put = $this->app()->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/linear', $owner, [
            'token' => self::LINEAR_TOKEN, 'team_key' => 'eng', 'webhook_secret' => 'lin_wh_signing_secret',
        ]));
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        $data = $this->body($put);
        self::assertSame('ENG', $data['connection']['target']);
        self::assertTrue($data['connection']['sync_enabled']);
        self::assertArrayNotHasKey('webhook_secret', $data);
        self::assertStringNotContainsString('lin_wh_signing_secret', (string) $put->getBody());
        self::assertSame('team-uuid', $this->conn->fetchOne("SELECT target_id FROM account_issue_trackers WHERE provider = 'linear'"));
    }

    public function test_linear_without_signing_secret_has_sync_disabled(): void
    {
        $owner = $this->member('owner@example.com');
        $this->http->queue('POST', self::LINEAR_URL, 200, ['data' => ['teams' => ['nodes' => [['id' => 'team-uuid', 'key' => 'ENG']]]]]);

        $data = $this->body($this->app()->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/linear', $owner, ['token' => self::LINEAR_TOKEN, 'team_key' => 'ENG'])));
        self::assertFalse($data['connection']['sync_enabled']);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function invalidBodies(): iterable
    {
        yield 'github missing token'   => ['github', ['repository' => 'acme/roadmap']];
        yield 'github bad repo'        => ['github', ['token' => self::GITHUB_TOKEN, 'repository' => 'https://evil.example/x']];
        yield 'github dot segment'     => ['github', ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/..']];
        yield 'github path traversal'  => ['github', ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap/../../user']];
        yield 'linear bad team key'    => ['linear', ['token' => self::LINEAR_TOKEN, 'team_key' => 'E NG']];
        yield 'token with whitespace'  => ['linear', ['token' => "lin api\nkey", 'team_key' => 'ENG']];
    }

    /** @param array<string, string> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function test_invalid_input_is_422_without_any_outbound_call(string $provider, array $body): void
    {
        $owner    = $this->member('owner@example.com');
        $response = $this->app()->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/' . $provider, $owner, $body));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $this->body($response)['error']['key']);
        self::assertSame([], $this->http->requests);
        self::assertFalse($this->conn->fetchOne('SELECT id FROM account_issue_trackers'));
    }

    public function test_rejected_token_is_not_stored_and_not_echoed(): void
    {
        $owner = $this->member('owner@example.com');
        $this->http->queue('GET', self::REPO_URL, 401, ['message' => 'Bad credentials ' . self::GITHUB_TOKEN]);

        $response = $this->app()->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap']));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('issue_tracker_invalid_credentials', $this->body($response)['error']['key']);
        self::assertStringNotContainsString('SUPERSECRET', (string) $response->getBody());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM account_issue_trackers'));
    }

    public function test_unreachable_provider_is_502(): void
    {
        $owner = $this->member('owner@example.com');
        $this->http->queueFailure('GET', self::REPO_URL);

        $response = $this->app()->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap']));
        self::assertSame(502, $response->getStatusCode());
        self::assertSame('issue_tracker_unavailable', $this->body($response)['error']['key']);
    }

    public function test_unknown_provider_is_404(): void
    {
        $owner = $this->member('owner@example.com');
        self::assertSame(404, $this->app()->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/jira', $owner, ['token' => 'x']))->getStatusCode());
        self::assertSame(404, $this->app()->handle($this->mutatingRequest('DELETE', '/admin/issue-trackers/jira', $owner))->getStatusCode());
    }

    public function test_plan_without_issue_tracker_gets_422_but_may_still_disconnect(): void
    {
        $owner = $this->member('owner@example.com');
        $app   = $this->app();
        $this->queueRepo();
        $app->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap']));

        $this->setAccountPlan($this->defaultAccountId(), 'lite');

        $get = $this->body($app->handle($this->getRequest('/admin/issue-trackers', $owner)));
        self::assertFalse($get['allowed']);
        self::assertNotNull($get['connections']['github']);

        $put = $app->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap']));
        self::assertSame(422, $put->getStatusCode());
        self::assertSame('plan_limit_issue_tracker', $this->body($put)['error']['key']);
        self::assertCount(1, $this->http->requests, 'no provider call once the plan gate refuses');

        self::assertSame(200, $app->handle($this->mutatingRequest('DELETE', '/admin/issue-trackers/github', $owner))->getStatusCode());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM account_issue_trackers'));
        self::assertSame(404, $app->handle($this->mutatingRequest('DELETE', '/admin/issue-trackers/github', $owner))->getStatusCode());
    }

    /** Owner answer 27a (2026-10-25): the account-level tracker connection stays usable while a board of the account is frozen. */
    public function test_frozen_board_does_not_lock_the_account_tracker_connection(): void
    {
        $owner = $this->member('owner@example.com');
        $this->insertBoard('frozen-tracker-board', ['frozen_at' => '2026-10-01 00:00:00']);
        $app = $this->app();
        $this->queueRepo();

        $put = $app->handle($this->mutatingRequest('PUT', '/admin/issue-trackers/github', $owner, ['token' => self::GITHUB_TOKEN, 'repository' => 'acme/roadmap']));
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertSame('Acme/Roadmap', $this->body($put)['connection']['target']);

        self::assertSame(200, $app->handle($this->mutatingRequest('DELETE', '/admin/issue-trackers/github', $owner))->getStatusCode());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM account_issue_trackers'));
    }

    public function test_cloud_routing_owner_of_account_a_cannot_read_or_change_account_b(): void
    {
        $accountA = $this->insertAccount(['slug' => 'alpha', 'plan' => 'pro']);
        $accountB = $this->insertAccount(['slug' => 'bravo', 'plan' => 'pro']);
        $ownerA   = $this->member('owner-alpha@example.com', 'owner', $accountA);
        $this->conn->insert('account_issue_trackers', [
            'account_id' => $accountB, 'provider' => 'github', 'token_encrypted' => 'b-token', 'token_hint' => '…bbbb',
            'target' => 'bravo/repo', 'target_id' => '', 'webhook_id' => str_repeat('b', 32), 'webhook_secret_encrypted' => null,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $app = $this->app(Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));

        $own = $this->body($app->handle($this->getRequest('/alpha/admin/issue-trackers', $ownerA)));
        self::assertNull($own['connections']['github'], 'account B connection is invisible in account A');
        self::assertContains($app->handle($this->getRequest('/bravo/admin/issue-trackers', $ownerA))->getStatusCode(), [403, 404]);
        self::assertContains($app->handle($this->mutatingRequest('DELETE', '/bravo/admin/issue-trackers/github', $ownerA))->getStatusCode(), [403, 404]);
        self::assertSame(404, $app->handle($this->mutatingRequest('DELETE', '/alpha/admin/issue-trackers/github', $ownerA))->getStatusCode());
        self::assertSame('b-token', $this->conn->fetchOne('SELECT token_encrypted FROM account_issue_trackers WHERE account_id = ?', [$accountB]));
        self::assertSame([], $this->http->requests);
    }
}
