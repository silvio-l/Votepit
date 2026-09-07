<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * End-to-end: idea creation and idea status changes fire the board-webhooks
 * fan-out (WebhookDispatcher), AND a failing/slow webhook receiver never
 * affects the triggering request's outcome (fail-open-for-availability
 * requirement — see WebhookDispatcher's class doc).
 *
 * Uses a synchronous defer runner + a fake sender injected via
 * IntegrationTestCase::createApp() so the whole HTTP round trip is
 * deterministic and network-free.
 */
final class IdeaWebhookFanoutTest extends IntegrationTestCase
{
    private function sessions(): SessionService
    {
        return new SessionService(str_repeat('a', 64), 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function mutatingRequest(string $method, string $path, ?int $userId, array $body): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();
        $cookies   = ['votepit_csrf' => $csrf->sign($csrfToken)];

        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }

        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $csrfToken)
            ->withParsedBody($body);
    }

    /** Backdated (5s) time-trap stamp — IdeaCreateAction's bot-defense field. */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    private function syncRunner(): \Closure
    {
        return static fn (\Closure $work): mixed => $work();
    }

    public function test_idea_creation_triggers_the_webhook_fan_out(): void
    {
        $this->insertBoard('demo');
        $userId = $this->insertUser('author@example.com');

        $sender = new class () implements WebhookSenderInterface {
            /** @var list<string> */
            public array $bodies = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $this->bodies[] = $body;
                return WebhookSendResult::response(200, null);
            }
        };

        $app = $this->createApp(webhookSender: $sender, webhookDeferRunner: $this->syncRunner());

        // Configure the board's webhook first (owner action) — must be owner.
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));

        $response = $app->handle($this->mutatingRequest('POST', '/demo/ideas', $userId, [
            'title'    => 'A brand new idea',
            'body'     => 'Please add this feature, it would help a lot.',
            '_form_at' => $this->validTimeTrap(),
        ]));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $sender->bodies);
        $decoded = json_decode($sender->bodies[0], true);
        self::assertSame('idea.created', $decoded['event']);
        self::assertSame('A brand new idea', $decoded['data']['title']);
    }

    public function test_idea_creation_succeeds_even_when_the_webhook_receiver_is_unreachable(): void
    {
        $this->insertBoard('demo');
        $userId  = $this->insertUser('author2@example.com');
        $ownerId = $this->insertUser('owner2@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::transportError('connection refused');
            }
        };
        $app = $this->createApp(webhookSender: $sender, webhookDeferRunner: $this->syncRunner());

        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $ownerId, ['url' => 'https://93.184.216.34/hook']));

        $response = $app->handle($this->mutatingRequest('POST', '/demo/ideas', $userId, [
            'title'    => 'Another idea entirely',
            'body'     => 'Description long enough to pass validation.',
            '_form_at' => $this->validTimeTrap(),
        ]));

        self::assertSame(201, $response->getStatusCode(), 'idea creation must succeed regardless of webhook delivery outcome');
        self::assertGreaterThanOrEqual(1, $sender->calls);
    }

    public function test_status_change_triggers_the_webhook_fan_out(): void
    {
        $boardId  = $this->insertBoard('demo');
        $authorId = $this->insertUser('idea-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Existing idea');

        $adminId = $this->insertUser('status-admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'owner');

        $sender = new class () implements WebhookSenderInterface {
            /** @var list<string> */
            public array $bodies = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $this->bodies[] = $body;
                return WebhookSendResult::response(200, null);
            }
        };

        $app = $this->createApp(webhookSender: $sender, webhookDeferRunner: $this->syncRunner());
        $app->handle($this->mutatingRequest('PUT', '/admin/boards/demo/webhook', $adminId, ['url' => 'https://93.184.216.34/hook']));

        $response = $app->handle($this->mutatingRequest('POST', "/demo/ideas/{$ideaId}/status", $adminId, ['status' => 'planned']));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $sender->bodies);
        $decoded = json_decode($sender->bodies[0], true);
        self::assertSame('idea.status_changed', $decoded['event']);
        self::assertSame('planned', $decoded['data']['status_to']);
    }
}
