<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Domain\PlanPolicy;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Security\Turnstile\InMemoryTurnstileHttpClient;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;

/**
 * Shared HTTP helpers for the guest idea submission tests (ADR 0006).
 *
 * Every guest request carries a valid CSRF pair (CSRF stays global for
 * guests), a REMOTE_ADDR unless a test wants it unresolvable, and the
 * Turnstile token GOOD_TOKEN that the in-memory siteverify stub reports as
 * solved for the action `anon_idea_submit`.
 */
abstract class GuestIdeaTestCase extends IntegrationTestCase
{
    protected const GOOD_TOKEN    = 'turnstile-ok';
    protected const GUEST_IP      = '203.0.113.10';
    protected const GUEST_ACTION  = 'anon_idea_submit';

    protected InMemoryTurnstileHttpClient $turnstile;

    protected ?object $webhookSender = null;

    /** Set by tests that build a prod-env app (CSRF cookie becomes `__Host-`). */
    protected bool $secureCsrf = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->turnstile = new InMemoryTurnstileHttpClient();
        $this->turnstile->stubSuccess(self::GOOD_TOKEN, self::GUEST_ACTION);
    }

    /** @param array<string, mixed> $overrides */
    protected function guestConfig(array $overrides = [], bool $turnstile = true): Config
    {
        // Explicitly on: routing_mode 'cloud' (cross-tenant tests) defaults the
        // installation kill switch to off — GuestSubmissionKillSwitchTest covers that.
        $base = ['guest_submission_enabled' => true];
        if ($turnstile) {
            $base['turnstile'] = ['site_key' => 'site-123', 'secret_key' => 'secret-456'];
        }

        return Config::fromArray($this->testConfigArray(array_merge($base, $overrides)));
    }

    /** @return App<null> */
    protected function guestApp(?Config $config = null, ?InMemoryMailer $mailer = null, ?PlanPolicy $planPolicy = null): App
    {
        $this->webhookSender = new class () implements WebhookSenderInterface {
            /** @var list<array<string, mixed>> */
            public array $events = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $decoded        = json_decode($body, true);
                $this->events[] = is_array($decoded) ? $decoded : [];

                return WebhookSendResult::response(200, null);
            }
        };

        return $this->createApp(
            mailer: $mailer,
            planPolicy: $planPolicy,
            webhookSender: $this->webhookSender,
            webhookDeferRunner: static fn (\Closure $work): mixed => $work(),
            config: $config ?? $this->guestConfig(),
            turnstileHttpClient: $this->turnstile,
        );
    }

    /** @return list<string> */
    protected function webhookEvents(): array
    {
        $sender = $this->webhookSender;
        if ($sender === null) {
            return [];
        }
        /** @var list<array<string, mixed>> $events */
        $events = $sender->events ?? [];

        return array_map(static fn (array $e): string => (string) ($e['event'] ?? ''), $events);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     */
    protected function request(string $method, string $path, array $body = [], array $cookies = [], ?string $ip = self::GUEST_IP, ?int $userId = null): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, $this->secureCsrf);
        $token = $csrf->generate();
        $all   = [$csrf->cookieName() => $csrf->sign($token), ...$cookies];
        if ($userId !== null) {
            $all['votepit_sess'] = $this->sessionCookie($userId);
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, $ip !== null ? ['REMOTE_ADDR' => $ip] : [])
            ->withCookieParams($all)
            ->withHeader('Accept', 'application/json');

        if ($method !== 'GET') {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody($body);
        }

        return $request;
    }

    /**
     * A complete, valid guest submission body.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function guestBody(string $title = 'More benches in the park', array $overrides = []): array
    {
        return array_merge([
            'title'           => $title,
            'body'            => 'It would be lovely to have more places to sit.',
            '_form_at'        => $this->validTimeTrap(),
            'turnstile_token' => self::GOOD_TOKEN,
        ], $overrides);
    }

    /**
     * @param App<null>             $app
     * @param array<string, mixed>  $overrides
     * @param array<string, string> $cookies
     */
    protected function submitAsGuest(App $app, string $path, string $title = 'More benches in the park', array $overrides = [], array $cookies = [], ?string $ip = self::GUEST_IP): ResponseInterface
    {
        return $app->handle($this->request('POST', $path, $this->guestBody($title, $overrides), $cookies, $ip));
    }

    protected function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');

        return $ts . '.' . $mac;
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, 'Expected a JSON body, got: ' . $response->getBody());

        return $data;
    }

    protected function errorKey(ResponseInterface $response): ?string
    {
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) && is_array($data['error'] ?? null) ? (string) ($data['error']['key'] ?? '') : null;
    }

    /** @return array<string, string> name => raw value of every Set-Cookie */
    protected function setCookies(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair]         = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);
            $cookies[$name] = $value;
        }

        return $cookies;
    }

    protected function ideaCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas');
    }

    /**
     * Board with guest submission switched on (premoderation 'anonymous' unless overridden).
     *
     * @param array<string, mixed> $overrides
     */
    protected function guestBoard(string $slug, array $overrides = []): int
    {
        return $this->insertBoard($slug, array_merge([
            'anonymous_submission_enabled' => 1,
            'premoderation'                => 'anonymous',
        ], $overrides));
    }
}
