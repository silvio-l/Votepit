<?php

declare(strict_types=1);

namespace Votepit\Tests\Webhook;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Persistence\BoardWebhookRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Security\Webhook\WebhookUrlGuard;
use Votepit\Webhook\WebhookDispatcher;

/**
 * WebhookDispatcher — payload signing, the SSRF guard being consulted on
 * every attempt AND every redirect hop, retry/backoff on transient
 * failures, no retry on a 4xx, and that delivery never runs inline (the
 * defer runner is the only thing that invokes the actual HTTP work).
 *
 * Uses a fake WebhookSenderInterface (recording exactly what it was called
 * with) — no real network access anywhere in this file.
 */
final class WebhookDispatcherTest extends TestCase
{
    private Connection $conn;
    private BoardWebhookRepository $repo;
    private EncryptionService $enc;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->conn->executeStatement(
            'CREATE TABLE board_webhooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_id INTEGER NOT NULL,
                url VARCHAR(2048) NOT NULL,
                secret_encrypted VARCHAR(512) NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $this->conn->executeStatement(
            'CREATE TABLE board_webhook_deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_webhook_id INTEGER NOT NULL,
                event VARCHAR(64) NOT NULL,
                success INTEGER NOT NULL,
                status_code INTEGER NULL,
                error VARCHAR(255) NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $this->conn->executeStatement(
            'CREATE TABLE rate_limits (
                bucket            VARCHAR(128) NOT NULL,
                window_seconds    INTEGER NOT NULL,
                count             INTEGER NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (bucket)
            )',
        );
        $this->repo = new BoardWebhookRepository($this->conn);
        $this->enc  = new EncryptionService(str_repeat('a', 64), 'board_webhook');
    }

    /** @param array<string, list<string>> $dnsTable */
    private function guard(array $dnsTable): WebhookUrlGuard
    {
        return new WebhookUrlGuard(static fn (string $host): array => $dnsTable[strtolower($host)] ?? []);
    }

    /** synchronous defer runner — runs the work immediately, deterministic for tests */
    private function syncRunner(): \Closure
    {
        return static fn (\Closure $work): mixed => $work();
    }

    private function seedWebhook(string $url, string $secret): int
    {
        return $this->repo->save(1, $url, $this->enc->encrypt($secret));
    }

    /** @return array{success:int, status_code:int|null, error:string|null}[] */
    private function deliveries(): array
    {
        return array_map(
            static fn (array $r): array => ['success' => (int) $r['success'], 'status_code' => $r['status_code'], 'error' => $r['error']],
            $this->conn->fetchAllAssociative('SELECT success, status_code, error FROM board_webhook_deliveries ORDER BY id ASC'),
        );
    }

    public function test_signs_payload_with_the_correct_secret_and_sends_expected_headers(): void
    {
        $webhookId = $this->seedWebhook('https://hook.example.com/receive', 'topsecret');

        $sender = new class () implements WebhookSenderInterface {
            /** @var list<array{target: WebhookTarget, headers: list<string>, body: string}> */
            public array $calls = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $this->calls[] = ['target' => $target, 'headers' => $headers, 'body' => $body];
                return WebhookSendResult::response(200, null);
            }
        };

        $dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->guard(['hook.example.com' => ['93.184.216.34']]),
            $sender,
            $this->enc,
            $this->syncRunner(),
        );

        $dispatcher->dispatchAsync(1, 'demo', 'idea.created', ['idea_id' => 42, 'title' => 'Hello']);

        self::assertCount(1, $sender->calls);
        $body = $sender->calls[0]['body'];
        $decoded = json_decode($body, true);
        self::assertSame('idea.created', $decoded['event']);
        self::assertSame('demo', $decoded['board_slug']);
        self::assertSame(42, $decoded['data']['idea_id']);

        $expectedSig = 'X-Votepit-Signature: sha256=' . hash_hmac('sha256', $body, 'topsecret');
        self::assertContains($expectedSig, $sender->calls[0]['headers']);
        self::assertContains('X-Votepit-Event: idea.created', $sender->calls[0]['headers']);

        self::assertSame([['success' => 1, 'status_code' => 200, 'error' => null]], $this->deliveries());
        self::assertSame('93.184.216.34', $sender->calls[0]['target']->ip);
        unset($webhookId);
    }

    public function test_no_active_webhook_never_calls_the_sender(): void
    {
        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::response(200, null);
            }
        };

        $dispatcher = new WebhookDispatcher($this->repo, $this->guard([]), $sender, $this->enc, $this->syncRunner());
        $dispatcher->dispatchAsync(999, 'nope', 'idea.created', []);

        self::assertSame(0, $sender->calls);
    }

    public function test_ssrf_guard_rejection_blocks_the_send_entirely_no_retry(): void
    {
        // The DNS table is empty — the hostname is unresolvable — so the
        // guard rejects it every time. If the dispatcher retried this as if
        // it were transient, the sender would still never be called (the
        // guard check happens BEFORE every send), so we assert 0 calls and
        // exactly ONE delivery-log row (no retry loop was entered).
        $this->seedWebhook('https://blocked.example.com/receive', 'secret');

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::response(200, null);
            }
        };

        $dispatcher = new WebhookDispatcher($this->repo, $this->guard([]), $sender, $this->enc, $this->syncRunner());
        $dispatcher->dispatchAsync(1, 'demo', 'idea.created', []);

        self::assertSame(0, $sender->calls);
        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame(0, $deliveries[0]['success']);
    }

    public function test_redirect_target_is_re_validated_by_the_ssrf_guard_and_blocked_if_private(): void
    {
        $this->seedWebhook('https://hook.example.com/receive', 'secret');

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                // First hop always redirects to an internal target.
                return WebhookSendResult::response(302, 'https://internal.example.com/steal');
            }
        };

        $dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->guard([
                'hook.example.com'     => ['93.184.216.34'],
                'internal.example.com' => ['10.0.0.5'], // private — must be rejected
            ]),
            $sender,
            $this->enc,
            $this->syncRunner(),
        );

        $dispatcher->dispatchAsync(1, 'demo', 'idea.created', []);

        self::assertSame(1, $sender->calls, 'must stop after the redirect target fails the SSRF check, never dial it');
        $deliveries = $this->deliveries();
        self::assertSame(0, $deliveries[0]['success']);
    }

    public function test_transient_transport_failure_is_retried_up_to_three_times_then_recorded_as_failed(): void
    {
        $this->seedWebhook('https://hook.example.com/receive', 'secret');

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::transportError('connection refused');
            }
        };

        $dispatcher = new WebhookDispatcher($this->repo, $this->guard(['hook.example.com' => ['93.184.216.34']]), $sender, $this->enc, $this->syncRunner(), [0, 0]);
        $dispatcher->dispatchAsync(1, 'demo', 'idea.created', []);

        self::assertSame(3, $sender->calls);
        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame(0, $deliveries[0]['success']);
    }

    public function test_client_error_status_is_not_retried(): void
    {
        $this->seedWebhook('https://hook.example.com/receive', 'secret');

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::response(410, null);
            }
        };

        $dispatcher = new WebhookDispatcher($this->repo, $this->guard(['hook.example.com' => ['93.184.216.34']]), $sender, $this->enc, $this->syncRunner());
        $dispatcher->dispatchAsync(1, 'demo', 'idea.created', []);

        self::assertSame(1, $sender->calls, 'a 4xx must not be retried');
        $deliveries = $this->deliveries();
        self::assertSame(410, $deliveries[0]['status_code']);
    }

    public function test_dispatch_async_uses_the_injected_defer_runner_instead_of_running_inline(): void
    {
        $this->seedWebhook('https://hook.example.com/receive', 'secret');

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::response(200, null);
            }
        };

        $deferredCalls = [];
        $dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->guard(['hook.example.com' => ['93.184.216.34']]),
            $sender,
            $this->enc,
            static function (\Closure $work) use (&$deferredCalls): void {
                $deferredCalls[] = $work; // captured, NOT invoked — proves dispatchAsync() doesn't run inline
            },
        );

        $dispatcher->dispatchAsync(1, 'demo', 'idea.created', []);

        self::assertSame(0, $sender->calls, 'no HTTP call must happen before the defer runner decides to run it');
        self::assertCount(1, $deferredCalls);

        // Running the captured closure now performs the actual delivery.
        ($deferredCalls[0])();
        self::assertSame(1, $sender->calls);
    }

    // ── per-board rate limiting (self-DoS protection) ───────────────────────

    public function test_dispatches_beyond_the_per_board_window_limit_are_blocked_without_calling_the_sender(): void
    {
        $this->seedWebhook('https://hook.example.com/receive', 'secret');

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::response(200, null);
            }
        };

        $dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->guard(['hook.example.com' => ['93.184.216.34']]),
            $sender,
            $this->enc,
            $this->syncRunner(),
            null,
            new RateLimiter($this->conn),
        );

        // Cap is 30/60s (WebhookDispatcher::RATE_LIMIT_MAX_PER_WINDOW) — fire
        // one more than that for board 1 and confirm the last one never
        // reaches the sender.
        for ($i = 0; $i < 30; ++$i) {
            $dispatcher->dispatchAsync(1, 'demo', 'idea.created', []);
        }
        self::assertSame(30, $sender->calls, 'the first 30 dispatches within the window must all go through');

        $dispatcher->dispatchAsync(1, 'demo', 'idea.created', []);
        self::assertSame(30, $sender->calls, 'the 31st dispatch within the same window must be rate-limited, not sent');

        $deliveries = $this->deliveries();
        self::assertCount(31, $deliveries);
        self::assertSame('rate_limited', $deliveries[30]['error']);
        self::assertSame(0, $deliveries[30]['success']);
    }

    public function test_rate_limit_is_scoped_per_board_not_global(): void
    {
        $webhookAId = $this->repo->save(1, 'https://hook.example.com/receive', $this->enc->encrypt('secret'));
        $webhookBId = $this->repo->save(2, 'https://hook.example.com/receive', $this->enc->encrypt('secret'));

        $sender = new class () implements WebhookSenderInterface {
            public int $calls = 0;

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                ++$this->calls;
                return WebhookSendResult::response(200, null);
            }
        };

        $dispatcher = new WebhookDispatcher(
            $this->repo,
            $this->guard(['hook.example.com' => ['93.184.216.34']]),
            $sender,
            $this->enc,
            $this->syncRunner(),
            null,
            new RateLimiter($this->conn),
        );

        for ($i = 0; $i < 30; ++$i) {
            $dispatcher->dispatchAsync(1, 'board-a', 'idea.created', []);
        }
        self::assertSame(30, $sender->calls);

        // Board 1 is now at its cap, but board 2 must be entirely unaffected.
        $dispatcher->dispatchAsync(2, 'board-b', 'idea.created', []);
        self::assertSame(31, $sender->calls, 'a different board must have its own independent rate-limit bucket');
        unset($webhookAId, $webhookBId);
    }
}
