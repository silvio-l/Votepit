<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Security\Turnstile\InMemoryTurnstileHttpClient;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the Turnstile anti-automation challenge (OWASP/BSI
 * audit remediation issue 20) on POST /{board}/ideas. See VoteTurnstileTest
 * for the shared rationale (suspicion bucket seeded directly, deterministic
 * — the acceptance criteria don't depend on actually tripping the global:ip
 * rate limit repeatedly, only on the resulting suspicion count).
 */
final class IdeaCreateTurnstileTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** Backdated time-trap stamp (5s in the past) — same as IdeaSubmitActionTest. */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /** @param array<string, string> $body */
    private function postIdea(string $slug, array $body, int $userId, string $ip): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $defaults = ['_csrf' => $token, '_form_at' => $this->validTimeTrap()];

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas', ['REMOTE_ADDR' => $ip])
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(array_merge($defaults, $body));
    }

    private function turnstileConfig(int $threshold = 3): Config
    {
        return Config::fromArray($this->testConfigArray([
            'turnstile' => [
                'site_key'                 => 'site-123',
                'secret_key'               => 'secret-456',
                'suspicion_threshold'      => $threshold,
                'suspicion_window_seconds' => 3600,
            ],
        ]));
    }

    private function makeIpSuspicious(string $ip, int $count): void
    {
        $this->conn->insert('rate_limits', [
            'bucket'            => 'turnstile:suspicion:ip:' . $ip,
            'window_seconds'    => 3600,
            'count'             => $count,
            'window_started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_ordinary_first_request_needs_no_challenge(): void
    {
        $this->insertBoard('turnstile-idea-1');
        $userId = $this->insertUser('turnstile-idea-1@example.com');

        $response = $this->createApp(config: $this->turnstileConfig())->handle($this->postIdea(
            'turnstile-idea-1',
            ['title' => 'A perfectly normal idea', 'body' => 'This is a normal description, nothing suspicious.'],
            $userId,
            '203.0.113.11',
        ));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_suspicious_ip_without_token_is_rejected(): void
    {
        $boardId = $this->insertBoard('turnstile-idea-2');
        $userId  = $this->insertUser('turnstile-idea-2@example.com');
        $ip      = '203.0.113.12';
        $this->makeIpSuspicious($ip, 3);

        $response = $this->createApp(config: $this->turnstileConfig())->handle($this->postIdea(
            'turnstile-idea-2',
            ['title' => 'A perfectly normal idea', 'body' => 'This is a normal description, nothing suspicious.'],
            $userId,
            $ip,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('turnstile_required', $data['error']['key'] ?? null);
        self::assertSame('site-123', $data['error']['site_key'] ?? null);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = ?', [$boardId]));
    }

    public function test_suspicious_ip_with_valid_token_succeeds(): void
    {
        $this->insertBoard('turnstile-idea-3');
        $userId = $this->insertUser('turnstile-idea-3@example.com');
        $ip     = '203.0.113.13';
        $this->makeIpSuspicious($ip, 3);

        $http = new InMemoryTurnstileHttpClient();
        $http->stubSuccess('good-token');

        $response = $this->createApp(config: $this->turnstileConfig(), turnstileHttpClient: $http)->handle($this->postIdea(
            'turnstile-idea-3',
            [
                'title'           => 'A perfectly normal idea',
                'body'            => 'This is a normal description, nothing suspicious.',
                'turnstile_token' => 'good-token',
            ],
            $userId,
            $ip,
        ));

        self::assertSame(201, $response->getStatusCode());
    }
}
