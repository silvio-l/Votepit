<?php

declare(strict_types=1);

namespace Votepit\Tests\Webhook\Chat;

use Votepit\Domain\PlanPolicy;
use Votepit\Persistence\BoardChatIntegrationRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookUrlGuard;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RecordingChatSender;
use Votepit\Webhook\Chat\ChatMessageFormatter;
use Votepit\Webhook\Chat\ChatNotificationDispatcher;
use Votepit\Webhook\Chat\ChatProvider;

/**
 * ChatNotificationDispatcher — plan gate, host allowlist + SSRF guard at
 * delivery time, no redirect following, bounded retries, deferred
 * execution and fail-safe behaviour. Fake sender, no network.
 */
final class ChatNotificationDispatcherTest extends IntegrationTestCase
{
    private const SLACK_URL   = 'https://hooks.slack.com/services/T0001/B0002/secrettoken';
    private const DISCORD_URL = 'https://discord.com/api/webhooks/123/secret_token';

    private EncryptionService $enc;
    private BoardChatIntegrationRepository $repo;

    /** @var list<\Closure> deferred work captured by a non-executing runner */
    private array $pending = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enc  = new EncryptionService(str_repeat('a', 64), 'board_chat_webhook');
        $this->repo = new BoardChatIntegrationRepository($this->conn);
    }

    /** @param list<WebhookSendResult> $results */
    private function sender(array $results = []): RecordingChatSender
    {
        return new RecordingChatSender($results);
    }

    /** @param array<string, list<string>> $dns */
    private function dispatcher(
        WebhookSenderInterface $sender,
        ?\Closure $runner = null,
        ?PlanPolicy $policy = null,
        array $dns = ['hooks.slack.com' => ['34.1.2.3'], 'discord.com' => ['162.159.1.1']],
    ): ChatNotificationDispatcher {
        $planPolicy = $policy ?? self::syntheticPlanPolicy();

        return new ChatNotificationDispatcher(
            $this->repo,
            new WebhookUrlGuard(static fn (string $h): array => $dns[$h] ?? []),
            $sender,
            $this->enc,
            $planPolicy,
            new \Votepit\Domain\AccountPlanResolver(new \Votepit\Persistence\AccountRepository($this->conn), new \Votepit\Persistence\AccountMemberRepository($this->conn), $planPolicy),
            new ChatMessageFormatter('https://app.example.com', false),
            $runner ?? static fn (\Closure $w): mixed => $w(),
            [0],
            new RateLimiter($this->conn),
        );
    }

    private function configure(int $boardId, ChatProvider $provider, string $url): void
    {
        $this->repo->save($this->defaultAccountId(), $boardId, $provider->value, $this->enc->encrypt($url), $provider->urlHint($url));
    }

    /** @return list<array<string, mixed>> */
    private function chatStatus(int $boardId): array
    {
        return $this->repo->listForAccountBoard($this->defaultAccountId(), $boardId);
    }

    public function test_delivers_to_every_configured_provider_pinned_to_the_resolved_ip(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configure($boardId, ChatProvider::Slack, self::SLACK_URL);
        $this->configure($boardId, ChatProvider::Discord, self::DISCORD_URL);
        $sender = $this->sender();

        $this->dispatcher($sender)->dispatchAsync($boardId, 'idea.created', ['idea_id' => 5, 'title' => 'Hello']);

        self::assertCount(2, $sender->calls);
        self::assertSame('34.1.2.3', $sender->calls[0]['target']->ip);
        self::assertSame(self::SLACK_URL, $sender->calls[0]['target']->url);
        self::assertStringContainsString('Hello', $sender->calls[0]['body']);
        self::assertSame('162.159.1.1', $sender->calls[1]['target']->ip);
        self::assertLessThanOrEqual(5, $sender->calls[0]['timeout']);
        foreach ($this->chatStatus($boardId) as $row) {
            self::assertTrue($row['last_delivery_ok']);
        }
    }

    public function test_work_is_deferred_until_the_runner_executes_it(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configure($boardId, ChatProvider::Slack, self::SLACK_URL);
        $sender = $this->sender();

        $this->dispatcher($sender, function (\Closure $w): void {
            $this->pending[] = $w;
        })->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);

        self::assertSame(0, $sender->callCount());
        self::assertCount(1, $this->pending);
        ($this->pending[0])();
        self::assertSame(1, $sender->callCount());
    }

    public function test_plan_without_chat_notifications_sends_nothing(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $this->configure($boardId, ChatProvider::Slack, self::SLACK_URL);
        $sender = $this->sender();

        $this->dispatcher($sender)->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);

        self::assertSame([], $sender->calls);
    }

    public function test_unsupported_events_are_ignored(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configure($boardId, ChatProvider::Slack, self::SLACK_URL);
        $sender = $this->sender();

        $this->dispatcher($sender)->dispatchAsync($boardId, 'comment.created', []);

        self::assertSame([], $sender->calls);
    }

    public function test_redirects_are_never_followed(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configure($boardId, ChatProvider::Slack, self::SLACK_URL);
        $sender = $this->sender([WebhookSendResult::response(302, 'https://127.0.0.1/admin')]);

        $this->dispatcher($sender)->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);

        self::assertCount(1, $sender->calls);
        self::assertSame('redirect_not_followed', $this->chatStatus($boardId)[0]['last_delivery_error']);
    }

    public function test_server_errors_are_retried_once_client_errors_are_not(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configure($boardId, ChatProvider::Slack, self::SLACK_URL);

        $sender = $this->sender([WebhookSendResult::response(503, null), WebhookSendResult::response(503, null)]);
        $this->dispatcher($sender)->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);
        self::assertCount(2, $sender->calls);
        self::assertSame('http_503', $this->chatStatus($boardId)[0]['last_delivery_error']);

        $sender = $this->sender([WebhookSendResult::response(404, null)]);
        $this->dispatcher($sender)->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);
        self::assertCount(1, $sender->calls);
        self::assertSame('http_404', $this->chatStatus($boardId)[0]['last_delivery_error']);
    }

    public function test_host_resolving_to_a_private_ip_is_never_dialed(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configure($boardId, ChatProvider::Slack, self::SLACK_URL);
        $sender = $this->sender();

        $this->dispatcher($sender, dns: ['hooks.slack.com' => ['10.0.0.5']])
            ->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);

        self::assertSame([], $sender->calls);
        self::assertSame('blocked_by_ssrf_guard', $this->chatStatus($boardId)[0]['last_delivery_error']);
    }

    public function test_a_stored_url_outside_the_allowlist_is_never_dialed(): void
    {
        $boardId = $this->insertBoard('demo');
        // Bypasses the action's validation, e.g. a tampered DB row.
        $this->repo->save($this->defaultAccountId(), $boardId, 'slack', $this->enc->encrypt('https://93.184.216.34/hook'), 'x');
        $sender = $this->sender();

        $this->dispatcher($sender)->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);

        self::assertSame([], $sender->calls);
        self::assertSame('blocked_by_url_policy', $this->chatStatus($boardId)[0]['last_delivery_error']);
    }

    public function test_a_broken_database_never_throws_into_the_caller(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->conn->executeStatement('DROP TABLE board_chat_integrations');

        $this->dispatcher($this->sender())->dispatchAsync($boardId, 'idea.created', ['idea_id' => 1, 'title' => 'x']);

        $this->addToAssertionCount(1);
    }
}
