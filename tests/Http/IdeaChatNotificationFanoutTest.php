<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Security\Password\InMemoryHibpHttpClient;
use Votepit\Security\SessionService;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookUrlGuard;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RecordingChatSender;

/**
 * End-to-end: idea creation / status change reach a configured Slack
 * webhook through the board-event fan-out, and an unreachable chat service
 * never affects the triggering request. Fake sender + stubbed DNS, sync
 * defer runner — no network.
 */
final class IdeaChatNotificationFanoutTest extends IntegrationTestCase
{
    private const SLACK_URL = 'https://hooks.slack.com/services/T0001/B0002/secrettoken';

    /** @param array<string, mixed> $body */
    private function mutatingRequest(string $method, string $path, int $userId, array $body): ServerRequestInterface
    {
        $csrf      = new CsrfService(str_repeat('a', 64), 3600, false);
        $csrfToken = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams([
                'votepit_csrf' => $csrf->sign($csrfToken),
                'votepit_sess' => (new SessionService(str_repeat('a', 64), 3600, false))->sign(['uid' => $userId, 'v' => 0]),
            ])
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $csrfToken)
            ->withParsedBody($body);
    }

    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    private function sender(bool $fail = false): RecordingChatSender
    {
        return new RecordingChatSender($fail ? [WebhookSendResult::transportError('connection refused'), WebhookSendResult::transportError('connection refused')] : []);
    }

    /**
     * Same wiring as createApp(), plus a WebhookUrlGuard with stubbed DNS
     * for hooks.slack.com (the chat hosts are fixed names, not IP literals).
     *
     * @return \Slim\App<null>
     */
    private function app(RecordingChatSender $sender): \Slim\App
    {
        return AppFactory::create(
            $this->testConfig(),
            $this->conn,
            new InMemoryMailer(),
            new AuditLogger($this->logFile),
            avatarDirOverride: $this->avatarDir,
            planPolicy: self::syntheticPlanPolicy(),
            extensions: [],
            webhookSender: $sender,
            webhookDeferRunner: static fn (\Closure $w): mixed => $w(),
            hibpHttpClient: new InMemoryHibpHttpClient(),
            webhookUrlGuard: new WebhookUrlGuard(static fn (string $h): array => $h === 'hooks.slack.com' ? ['34.1.2.3'] : []),
        );
    }

    public function test_new_idea_is_posted_to_slack(): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $authorId = $this->insertUser('author@example.com');
        $sender   = $this->sender();
        $app      = $this->app($sender);

        self::assertSame(200, $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/slack', $ownerId, ['url' => self::SLACK_URL]))->getStatusCode());

        $response = $app->handle($this->mutatingRequest('POST', '/demo/ideas', $authorId, [
            'title'    => 'Chat-worthy idea',
            'body'     => 'Long enough description for validation.',
            '_form_at' => $this->validTimeTrap(),
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $sender->calls);
        self::assertSame('hooks.slack.com', $sender->calls[0]['target']->host);
        $payload = json_decode($sender->calls[0]['body'], true);
        self::assertIsArray($payload);
        self::assertStringContainsString('Chat-worthy idea', (string) $payload['text']);
        self::assertStringNotContainsString('author@example.com', $sender->calls[0]['body']);
    }

    public function test_status_change_is_posted_and_a_dead_chat_service_does_not_break_the_request(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $ideaId = $this->seedIdea($boardId, $adminId, 'Existing idea');
        $sender = $this->sender(fail: true);
        $app    = $this->app($sender);

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/slack', $adminId, ['url' => self::SLACK_URL]));
        $response = $app->handle($this->mutatingRequest('POST', "/demo/ideas/{$ideaId}/status", $adminId, ['status' => 'planned']));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($sender->calls);
        self::assertStringContainsString('Planned', $sender->calls[0]['body']);
        self::assertSame('transport_error', $this->conn->fetchOne('SELECT last_delivery_error FROM board_chat_integrations'));
    }

    public function test_merging_a_duplicate_is_posted_to_slack(): void
    {
        $boardId = $this->insertBoard('demo');
        $adminId = $this->insertUser('admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');
        $target = $this->seedIdea($boardId, $adminId, 'Dark mode');
        $source = $this->seedIdea($boardId, $adminId, 'Dark mode please');
        $sender = $this->sender();
        $app    = $this->app($sender);

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/chat-notifications/slack', $adminId, ['url' => self::SLACK_URL]));
        $response = $app->handle($this->mutatingRequest('POST', "/demo/ideas/{$source}/merge", $adminId, ['target_id' => $target]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $sender->calls);
        $text = (string) (json_decode($sender->calls[0]['body'], true)['text'] ?? '');
        self::assertStringContainsString('Dark mode please', $text);
        self::assertStringContainsString("/demo/idea/{$target}", $text, 'Links to the canonical idea (merged_into_id).');
    }
}
