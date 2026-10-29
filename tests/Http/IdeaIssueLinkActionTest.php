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
 * "Create issue from idea" (issue 19): AuthZ (moderator+), plan gate,
 * connection required, issue content (escaped body + link back), link
 * persistence/duplicates and cross-tenant isolation.
 */
final class IdeaIssueLinkActionTest extends IntegrationTestCase
{
    private const GITHUB_TOKEN = 'test-gh-token-SUPERSECRET-VALUE';
    private const LINEAR_TOKEN = 'lin_api_SUPERSECRETLINEARKEY';
    private const LINEAR_URL   = 'https://api.linear.app/graphql';

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

    private const ISSUES_URL = 'https://api.github.com/repos/Acme/Roadmap/issues';

    private function connect(string $provider = 'github', ?int $accountId = null): void
    {
        $this->conn->insert('account_issue_trackers', [
            'account_id'               => $accountId ?? $this->defaultAccountId(),
            'provider'                 => $provider,
            'token_encrypted'          => (new EncryptionService(str_repeat('a', 64), 'issue_tracker_token'))->encrypt($provider === 'github' ? self::GITHUB_TOKEN : self::LINEAR_TOKEN),
            'token_hint'               => '…xxxx',
            'target'                   => $provider === 'github' ? 'Acme/Roadmap' : 'ENG',
            'target_id'                => $provider === 'github' ? '' : 'team-uuid',
            'webhook_id'               => bin2hex(random_bytes(16)),
            'webhook_secret_encrypted' => null,
            'created_at'               => '2026-01-01 00:00:00',
            'updated_at'               => '2026-01-01 00:00:00',
        ]);
    }

    private function queueCreated(int $number = 7): void
    {
        $this->http->queue('POST', self::ISSUES_URL, 201, [
            'id' => 9000 + $number, 'number' => $number, 'html_url' => 'https://github.com/Acme/Roadmap/issues/' . $number,
        ]);
    }

    public function test_anon_and_plain_user_are_rejected(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'));
        $this->connect();
        $app = $this->app();

        self::assertSame(401, $app->handle($this->getRequest("/admin/boards/demo/ideas/{$ideaId}/issue-links", null))->getStatusCode());
        $plain = $this->insertUser('plain@example.com');
        self::assertSame(403, $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $plain))->getStatusCode());
        self::assertSame([], $this->http->requests);
    }

    public function test_moderator_creates_a_github_issue_and_the_link_is_listed(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'Dark mode', ['body' => "Please add it.\n@everyone ```js\nalert(1)\n``` ![x](https://evil.example/p.png)"]);
        $this->connect();
        $moderator = $this->member('mod@example.com', 'moderator');
        $app       = $this->app();
        $this->queueCreated();

        $post = $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $moderator));
        self::assertSame(201, $post->getStatusCode(), (string) $post->getBody());
        self::assertSame(['provider' => 'github', 'external_key' => '#7', 'url' => 'https://github.com/Acme/Roadmap/issues/7'], $this->body($post)['link']);

        $sent = $this->http->requests[0];
        self::assertSame(self::ISSUES_URL, $sent['url']);
        self::assertSame('Dark mode', $sent['json']['title'] ?? null);
        $issueBody = (string) ($sent['json']['body'] ?? '');
        self::assertStringStartsWith("````text\n", $issueBody, 'fence longer than any backtick run in the idea text');
        self::assertStringContainsString("@everyone ```js", $issueBody);
        self::assertStringEndsWith("Votepit idea: http://localhost:8000/demo/idea/{$ideaId}", $issueBody);

        $get = $this->body($app->handle($this->getRequest("/admin/boards/demo/ideas/{$ideaId}/issue-links", $moderator)));
        self::assertTrue($get['allowed']);
        self::assertSame(['github'], $get['providers']);
        self::assertCount(1, $get['links']);
        self::assertSame('#7', $get['links'][0]['external_key']);
        self::assertSame((string) 9007, $this->conn->fetchOne('SELECT external_id FROM idea_issue_links WHERE idea_id = ?', [$ideaId]));
    }

    public function test_linear_issue_goes_to_the_stored_team(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'Export');
        $this->connect('linear');
        $owner = $this->member('owner@example.com');
        $this->http->queue('POST', self::LINEAR_URL, 200, ['data' => ['issueCreate' => ['success' => true, 'issue' => [
            'id' => 'lin-issue-uuid', 'identifier' => 'ENG-3', 'url' => 'https://linear.app/acme/issue/ENG-3/export',
        ]]]]);

        $post = $this->app()->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/linear", $owner));
        self::assertSame(201, $post->getStatusCode(), (string) $post->getBody());
        self::assertSame('team-uuid', $this->http->requests[0]['json']['variables']['input']['teamId'] ?? null);
        self::assertContains('Authorization: ' . self::LINEAR_TOKEN, $this->http->requests[0]['headers']);
    }

    public function test_second_create_for_the_same_idea_is_409_without_outbound_call(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'));
        $this->connect();
        $owner = $this->member('owner@example.com');
        $app   = $this->app();
        $this->queueCreated();
        $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner));

        $again = $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('issue_link_exists', $this->body($again)['error']['key']);
        self::assertCount(1, $this->http->requests);
    }

    public function test_not_connected_is_422(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'));
        $owner   = $this->member('owner@example.com');

        $response = $this->app()->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('issue_tracker_not_connected', $this->body($response)['error']['key']);
    }

    public function test_plan_gate_blocks_creation_but_not_unlinking(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'));
        $this->connect();
        $owner = $this->member('owner@example.com');
        $app   = $this->app();
        $this->queueCreated();
        $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner));

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $this->conn->delete('idea_issue_links', ['idea_id' => $ideaId]);
        $denied = $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner));
        self::assertSame(422, $denied->getStatusCode());
        self::assertSame('plan_limit_issue_tracker', $this->body($denied)['error']['key']);
        self::assertCount(1, $this->http->requests);
        self::assertFalse($this->body($app->handle($this->getRequest("/admin/boards/demo/ideas/{$ideaId}/issue-links", $owner)))['allowed']);
    }

    public function test_unlink_removes_only_the_link(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'));
        $this->connect();
        $owner = $this->member('owner@example.com');
        $app   = $this->app();
        $this->queueCreated();
        $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner));

        self::assertSame(200, $app->handle($this->mutatingRequest('DELETE', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner))->getStatusCode());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM idea_issue_links'));
        self::assertSame(404, $app->handle($this->mutatingRequest('DELETE', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner))->getStatusCode());
        self::assertCount(1, $this->http->requests, 'unlinking never calls the provider');
    }

    public function test_provider_rejection_stores_no_link(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'));
        $this->connect();
        $owner = $this->member('owner@example.com');
        $this->http->queue('POST', self::ISSUES_URL, 410, ['message' => 'Issues are disabled for this repo']);

        $response = $this->app()->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$ideaId}/issue-links/github", $owner));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('issue_tracker_rejected', $this->body($response)['error']['key']);
        self::assertStringNotContainsString('SUPERSECRET', (string) $response->getBody());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM idea_issue_links'));
    }

    public function test_pending_idea_and_idea_of_another_board_are_404(): void
    {
        $boardId = $this->insertBoard('demo');
        $other   = $this->insertBoard('other');
        $author  = $this->insertUser('author@example.com');
        $pending = $this->seedIdea($boardId, $author, 'Pending', ['moderation_state' => 'pending']);
        $foreign = $this->seedIdea($other, $author, 'Other board');
        $this->connect();
        $owner = $this->member('owner@example.com');
        $app   = $this->app();

        self::assertSame(404, $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$pending}/issue-links/github", $owner))->getStatusCode());
        self::assertSame(404, $app->handle($this->mutatingRequest('POST', "/admin/boards/demo/ideas/{$foreign}/issue-links/github", $owner))->getStatusCode());
        self::assertSame([], $this->http->requests);
    }

    public function test_self_host_owner_cannot_link_an_idea_of_a_foreign_account(): void
    {
        $foreignAccount = $this->insertAccount(['slug' => 'acct-issue-foreign', 'plan' => 'pro']);
        $foreignBoard   = $this->insertBoard('foreign-board', ['account_id' => $foreignAccount]);
        $foreignIdea    = $this->seedIdea($foreignBoard, $this->insertUser('author@example.com'));
        $this->connect();
        $owner = $this->member('owner@example.com');
        $app   = $this->app();

        self::assertSame(404, $app->handle($this->getRequest("/admin/boards/foreign-board/ideas/{$foreignIdea}/issue-links", $owner))->getStatusCode());
        self::assertSame(404, $app->handle($this->mutatingRequest('POST', "/admin/boards/foreign-board/ideas/{$foreignIdea}/issue-links/github", $owner))->getStatusCode());
        self::assertSame([], $this->http->requests);
        self::assertFalse($this->conn->fetchOne('SELECT id FROM idea_issue_links'));
    }

    public function test_cloud_routing_issue_links_stay_inside_the_account(): void
    {
        $accountA = $this->insertAccount(['slug' => 'alpha', 'plan' => 'pro']);
        $accountB = $this->insertAccount(['slug' => 'bravo', 'plan' => 'pro']);
        $boardA   = $this->insertBoard('board-a', ['account_id' => $accountA]);
        $boardB   = $this->insertBoard('board-b', ['account_id' => $accountB]);
        $author   = $this->insertUser('author@example.com');
        $ideaA    = $this->seedIdea($boardA, $author, 'Alpha idea');
        $ideaB    = $this->seedIdea($boardB, $author, 'Bravo idea');
        $this->connect('github', $accountA);
        $ownerA = $this->member('owner-alpha@example.com', 'owner', $accountA);
        $app    = $this->app(Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));

        // Account B's idea through account A's path / account B's path.
        self::assertSame(404, $app->handle($this->mutatingRequest('POST', "/alpha/admin/boards/board-b/ideas/{$ideaB}/issue-links/github", $ownerA))->getStatusCode());
        self::assertSame(404, $app->handle($this->mutatingRequest('POST', "/alpha/admin/boards/board-a/ideas/{$ideaB}/issue-links/github", $ownerA))->getStatusCode());
        self::assertContains($app->handle($this->mutatingRequest('POST', "/bravo/admin/boards/board-b/ideas/{$ideaB}/issue-links/github", $ownerA))->getStatusCode(), [403, 404]);
        self::assertSame([], $this->http->requests);

        // Own idea works and links back with the account slug.
        $this->queueCreated();
        self::assertSame(201, $app->handle($this->mutatingRequest('POST', "/alpha/admin/boards/board-a/ideas/{$ideaA}/issue-links/github", $ownerA))->getStatusCode());
        self::assertStringEndsWith("http://localhost:8000/alpha/board-a/idea/{$ideaA}", (string) ($this->http->requests[0]['json']['body'] ?? ''));
        self::assertSame((string) $accountA, (string) $this->conn->fetchOne('SELECT account_id FROM idea_issue_links'));
    }
}
