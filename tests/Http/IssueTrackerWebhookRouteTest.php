<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Votepit\Config;
use Votepit\Security\EncryptionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Inbound Linear/GitHub status-sync webhooks (issue 19): signature before
 * anything else, replay window, CSRF exemption only with the signature
 * header, plan gate, owner-defined done statuses and cross-tenant
 * isolation (a delivery for account A never touches account B).
 */
final class IssueTrackerWebhookRouteTest extends IntegrationTestCase
{
    private const SECRET = 'whsec-0123456789abcdef'; // gitleaks:allow (test fixture)

    /** Seeds a connection with a known webhook id + secret; returns the webhook id. */
    private function connect(string $provider, ?int $accountId = null, string $target = 'acme/roadmap'): string
    {
        $hook = bin2hex(random_bytes(16));
        $this->conn->insert('account_issue_trackers', [
            'account_id'               => $accountId ?? $this->defaultAccountId(),
            'provider'                 => $provider,
            'token_encrypted'          => 'unused',
            'token_hint'               => '…xxxx',
            'target'                   => $target,
            'target_id'                => '',
            'webhook_id'               => $hook,
            'webhook_secret_encrypted' => (new EncryptionService(str_repeat('a', 64), 'issue_tracker_webhook'))->encrypt(self::SECRET),
            'created_at'               => '2026-01-01 00:00:00',
            'updated_at'               => '2026-01-01 00:00:00',
        ]);

        return $hook;
    }

    private function link(int $ideaId, string $provider, string $externalId, ?int $accountId = null): void
    {
        $this->conn->insert('idea_issue_links', [
            'account_id'   => $accountId ?? $this->defaultAccountId(),
            'idea_id'      => $ideaId,
            'provider'     => $provider,
            'external_id'  => $externalId,
            'external_key' => '#1',
            'url'          => 'https://github.com/acme/roadmap/issues/1',
            'created_at'   => '2026-01-01 00:00:00',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function githubClosed(array $payload = [], int $issueId = 555, string $repo = 'Acme/Roadmap'): string
    {
        return (string) json_encode(array_replace_recursive([
            'action'     => 'closed',
            'issue'      => ['id' => $issueId, 'number' => 1, 'state_reason' => 'completed'],
            'repository' => ['full_name' => $repo],
        ], $payload));
    }

    private function linearCompleted(string $issueId = 'lin-1', ?int $timestampMs = null): string
    {
        return (string) json_encode([
            'type'             => 'Issue',
            'action'           => 'update',
            'data'             => ['id' => $issueId, 'state' => ['type' => 'completed']],
            'webhookTimestamp' => $timestampMs ?? (int) floor(microtime(true) * 1000),
        ]);
    }

    /** @param array<string, string> $headers */
    private function deliver(string $provider, string $hook, string $rawBody, ?string $signature = null, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/issue-tracker-webhooks/{$provider}/{$hook}", ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($rawBody));

        $hmac      = hash_hmac('sha256', $rawBody, self::SECRET);
        $signature ??= $provider === 'github' ? 'sha256=' . $hmac : $hmac;
        if ($signature !== '') {
            $request = $request->withHeader($provider === 'github' ? 'X-Hub-Signature-256' : 'Linear-Signature', $signature);
        }
        if ($provider === 'github') {
            $request = $request->withHeader('X-GitHub-Event', 'issues');
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function ideaStatus(int $ideaId): string
    {
        return (string) $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]);
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, (string) $response->getBody());
        return $decoded;
    }

    public function test_github_closed_issue_moves_the_linked_idea_to_done(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'Dark mode', ['status' => 'in_progress']);
        $hook    = $this->connect('github');
        $this->link($ideaId, 'github', '555');

        $response = $this->createApp()->handle($this->deliver('github', $hook, $this->githubClosed()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('updated', $this->body($response)['outcome']);
        self::assertSame('done', $this->ideaStatus($ideaId));
        self::assertArrayNotHasKey('Set-Cookie', $response->getHeaders(), 'no CSRF/session cookie on a machine webhook');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('idea.status.changed', $log);
        self::assertStringContainsString('issue_tracker', $log);
    }

    public function test_status_change_notifies_the_author(): void
    {
        $boardId = $this->insertBoard('demo');
        $author  = $this->insertUser('author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Dark mode', ['status' => 'planned']);
        $hook    = $this->connect('github');
        $this->link($ideaId, 'github', '555');

        $this->createApp()->handle($this->deliver('github', $hook, $this->githubClosed()));

        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'idea_status_changed'", [$author]));
    }

    /**
     * The board's "reason required" switch (migration 0115) governs human
     * decisions only: the automated sync has no decision text and bypasses
     * IdeaStatusAction, so a closed issue still moves the idea to done.
     */
    public function test_board_reason_requirement_does_not_block_the_automated_issue_sync(): void
    {
        $boardId = $this->insertBoard('demo', ['status_reason_required' => 1]);
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'Dark mode', ['status' => 'in_progress']);
        $hook    = $this->connect('github');
        $this->link($ideaId, 'github', '555');

        $response = $this->createApp()->handle($this->deliver('github', $hook, $this->githubClosed()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('updated', $this->body($response)['outcome']);
        self::assertSame('done', $this->ideaStatus($ideaId));
    }

    public function test_linear_completed_issue_moves_the_linked_idea_to_done(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'Export', ['status' => 'planned']);
        $hook    = $this->connect('linear', null, 'ENG');
        $this->link($ideaId, 'linear', 'lin-1');

        $response = $this->createApp()->handle($this->deliver('linear', $hook, $this->linearCompleted()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('done', $this->ideaStatus($ideaId));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function badSignatures(): iterable
    {
        yield 'github missing header' => ['github', ''];
        yield 'github wrong secret'   => ['github', 'sha256=' . hash_hmac('sha256', 'x', 'wrong')];
        yield 'github no prefix'      => ['github', str_repeat('0', 64)];
        yield 'linear missing header' => ['linear', ''];
        yield 'linear wrong secret'   => ['linear', str_repeat('f', 64)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSignatures')]
    public function test_invalid_signature_is_rejected_and_changes_nothing(string $provider, string $signature): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'X', ['status' => 'planned']);
        $hook    = $this->connect($provider, null, $provider === 'github' ? 'acme/roadmap' : 'ENG');
        $this->link($ideaId, $provider, $provider === 'github' ? '555' : 'lin-1');
        $raw = $provider === 'github' ? $this->githubClosed() : $this->linearCompleted();

        $response = $this->createApp()->handle($this->deliver($provider, $hook, $raw, $signature));

        // Without the signature header the CSRF gate already refuses (403);
        // with a wrong one the webhook middleware does (401).
        self::assertContains($response->getStatusCode(), [401, 403]);
        self::assertStringNotContainsString(self::SECRET, (string) $response->getBody());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_tampered_body_with_the_original_signature_is_401(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'X', ['status' => 'planned']);
        $hook    = $this->connect('github');
        $this->link($ideaId, 'github', '555');
        $signature = 'sha256=' . hash_hmac('sha256', $this->githubClosed([], 999), self::SECRET);

        $response = $this->createApp()->handle($this->deliver('github', $hook, $this->githubClosed(), $signature));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_unknown_hook_and_connection_without_secret_are_404(): void
    {
        $app = $this->createApp();
        self::assertSame(404, $app->handle($this->deliver('github', str_repeat('0', 32), $this->githubClosed()))->getStatusCode());

        $hook = $this->connect('github');
        $this->conn->update('account_issue_trackers', ['webhook_secret_encrypted' => null], ['webhook_id' => $hook]);
        self::assertSame(404, $app->handle($this->deliver('github', $hook, $this->githubClosed()))->getStatusCode());

        // A GitHub hook id is not valid on the Linear route.
        $hook2 = $this->connect('github', $this->insertAccount());
        self::assertSame(404, $app->handle($this->deliver('linear', $hook2, $this->linearCompleted()))->getStatusCode());
    }

    public function test_linear_delivery_outside_the_replay_window_is_401(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'X', ['status' => 'planned']);
        $hook    = $this->connect('linear', null, 'ENG');
        $this->link($ideaId, 'linear', 'lin-1');
        $stale = $this->linearCompleted('lin-1', (int) floor(microtime(true) * 1000) - 5 * 60 * 1000);

        self::assertSame(401, $this->createApp()->handle($this->deliver('linear', $hook, $stale))->getStatusCode());
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_irrelevant_events_are_acknowledged_and_ignored(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'X', ['status' => 'planned']);
        $hook    = $this->connect('github');
        $this->link($ideaId, 'github', '555');
        $app = $this->createApp();

        $ping = $app->handle($this->deliver('github', $hook, '{"zen":"Keep it logically awesome.","hook_id":1}', null, ['X-GitHub-Event' => 'ping']));
        self::assertSame(200, $ping->getStatusCode());
        self::assertSame(200, $app->handle($this->deliver('github', $hook, $this->githubClosed(['issue' => ['state_reason' => 'not_planned']])))->getStatusCode());
        self::assertSame(200, $app->handle($this->deliver('github', $hook, $this->githubClosed([], 555, 'other/repo')))->getStatusCode());
        $unlinked = $app->handle($this->deliver('github', $hook, $this->githubClosed([], 777)));
        self::assertSame('unlinked', $this->body($unlinked)['outcome']);
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_plan_without_issue_tracker_ignores_deliveries(): void
    {
        $boardId = $this->insertBoard('demo');
        $ideaId  = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'X', ['status' => 'planned']);
        $hook    = $this->connect('github');
        $this->link($ideaId, 'github', '555');
        $this->setAccountPlan($this->defaultAccountId(), 'lite');

        $response = $this->createApp()->handle($this->deliver('github', $hook, $this->githubClosed()));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ignored', $this->body($response)['outcome']);
        self::assertSame('planned', $this->ideaStatus($ideaId));
    }

    public function test_declined_or_already_done_ideas_are_left_unchanged(): void
    {
        $boardId  = $this->insertBoard('demo');
        $author   = $this->insertUser('author@example.com');
        $declined = $this->seedIdea($boardId, $author, 'Declined', ['status' => 'declined']);
        $archived = $this->seedIdea($boardId, $author, 'Archived', ['status' => 'archived']);
        $hook     = $this->connect('github');
        $this->link($declined, 'github', '1');
        $this->link($archived, 'github', '2');
        $app = $this->createApp();

        self::assertSame('unchanged', $this->body($app->handle($this->deliver('github', $hook, $this->githubClosed([], 1))))['outcome']);
        self::assertSame('unchanged', $this->body($app->handle($this->deliver('github', $hook, $this->githubClosed([], 2))))['outcome']);
        self::assertSame('declined', $this->ideaStatus($declined));
        self::assertSame('archived', $this->ideaStatus($archived));
    }

    /**
     * Seeds a customized registry: system rows + extra rows [key, category, hidden].
     *
     * @param list<array{0: string, 1: string, 2: bool}> $extra
     * @param list<string>                               $hiddenSystem
     */
    private function customStatuses(int $boardId, array $extra, array $hiddenSystem = []): void
    {
        $this->conn->update('boards', ['statuses_customized' => 1], ['id' => $boardId]);
        foreach (['open', 'planned', 'in_progress', 'done', 'declined', 'archived'] as $i => $key) {
            $this->conn->insert('board_statuses', ['board_id' => $boardId, 'status_key' => $key, 'category' => $key, 'position' => $i, 'is_system' => 1, 'is_hidden' => in_array($key, $hiddenSystem, true) ? 1 : 0]);
        }
        foreach ($extra as $i => [$key, $category, $hidden]) {
            $this->conn->insert('board_statuses', ['board_id' => $boardId, 'status_key' => $key, 'category' => $category, 'label_en' => ucfirst($key), 'position' => 10 + $i, 'is_system' => 0, 'is_hidden' => $hidden ? 1 : 0]);
        }
    }

    public function test_owner_defined_done_status_is_used_when_system_done_is_hidden(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->customStatuses($boardId, [['shipped', 'done', false]], ['done']);
        $ideaId = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'X', ['status' => 'in_progress']);
        $hook   = $this->connect('github');
        $this->link($ideaId, 'github', '555');

        $this->createApp()->handle($this->deliver('github', $hook, $this->githubClosed()));
        self::assertSame('shipped', $this->ideaStatus($ideaId));
    }

    public function test_no_visible_done_status_leaves_the_idea_unchanged(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->customStatuses($boardId, [['shipped', 'done', true]], ['done']);
        $ideaId = $this->seedIdea($boardId, $this->insertUser('author@example.com'), 'X', ['status' => 'in_progress']);
        $hook   = $this->connect('github');
        $this->link($ideaId, 'github', '555');

        $response = $this->createApp()->handle($this->deliver('github', $hook, $this->githubClosed()));
        self::assertSame('unchanged', $this->body($response)['outcome']);
        self::assertSame('in_progress', $this->ideaStatus($ideaId));
    }

    public function test_delivery_for_account_a_never_touches_account_b(): void
    {
        $accountA = $this->insertAccount(['slug' => 'alpha', 'plan' => 'pro']);
        $accountB = $this->insertAccount(['slug' => 'bravo', 'plan' => 'pro']);
        $author   = $this->insertUser('author@example.com');
        $ideaA    = $this->seedIdea($this->insertBoard('board-a', ['account_id' => $accountA]), $author, 'A', ['status' => 'planned']);
        $ideaB    = $this->seedIdea($this->insertBoard('board-b', ['account_id' => $accountB]), $author, 'B', ['status' => 'planned']);
        $hookA    = $this->connect('github', $accountA);
        $this->connect('github', $accountB);
        // Same provider-side issue id linked in BOTH accounts (e.g. both
        // pointed at the same public repo) — only A's idea may move.
        $this->link($ideaA, 'github', '555', $accountA);
        $this->link($ideaB, 'github', '555', $accountB);
        // A forged link row claiming account A for B's idea must not help either.
        $ideaB2 = $this->seedIdea((int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'board-b'"), $author, 'B2', ['status' => 'planned']);
        $this->link($ideaB2, 'github', '556', $accountA);

        $app = $this->createApp(config: Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));
        self::assertSame(200, $app->handle($this->deliver('github', $hookA, $this->githubClosed()))->getStatusCode());
        self::assertSame('done', $this->ideaStatus($ideaA));
        self::assertSame('planned', $this->ideaStatus($ideaB));

        $forged = $app->handle($this->deliver('github', $hookA, $this->githubClosed([], 556)));
        self::assertSame('unlinked', $this->body($forged)['outcome']);
        self::assertSame('planned', $this->ideaStatus($ideaB2));
    }

    public function test_webhook_route_is_csrf_exempt_only_with_the_signature_header_and_rate_limited(): void
    {
        $hook = $this->connect('github');
        $app  = $this->createApp(config: Config::fromArray($this->testConfigArray(['rate_limits' => ['issue-tracker:webhook' => ['limit' => 2, 'window' => 60]]])));

        self::assertSame(403, $app->handle($this->deliver('github', $hook, '{}', ''))->getStatusCode(), 'no signature header → CSRF gate');
        self::assertSame(200, $app->handle($this->deliver('github', $hook, '{}', null, ['X-GitHub-Event' => 'ping']))->getStatusCode());
        self::assertSame(200, $app->handle($this->deliver('github', $hook, '{}', null, ['X-GitHub-Event' => 'ping']))->getStatusCode());
        self::assertSame(429, $app->handle($this->deliver('github', $hook, '{}', null, ['X-GitHub-Event' => 'ping']))->getStatusCode());
    }
}
