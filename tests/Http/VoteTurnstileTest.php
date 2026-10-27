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
 * audit remediation issue 20) on POST /{board}/ideas/{id}/vote.
 *
 * Acceptance criteria: an ordinary first request from an IP needs no
 * `turnstile_token` and succeeds normally; once that IP is "suspicious"
 * (its turnstile:suspicion:ip:<ip> bucket has reached the configured
 * threshold — normally only reached after repeatedly tripping the existing
 * global:ip rate limit, see TurnstileGuard/AppFactory wiring), a request
 * without a valid token is rejected with 422/turnstile_required, and the
 * same request with a valid (stubbed) token succeeds.
 */
final class VoteTurnstileTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postVote(string $slug, int $ideaId, string $value, int $userId, string $ip, ?string $turnstileToken = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $body = ['_csrf' => $token, 'value' => $value];
        if ($turnstileToken !== null) {
            $body['turnstile_token'] = $turnstileToken;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/vote', ['REMOTE_ADDR' => $ip])
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody($body);
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

    /** Seeds the suspicion bucket for an IP directly, past the given threshold. */
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
        $boardId = $this->insertBoard('turnstile-vote-1');
        $userId  = $this->insertUser('turnstile-vote-1@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp(config: $this->turnstileConfig())
            ->handle($this->postVote('turnstile-vote-1', $ideaId, 'up', $userId, '203.0.113.1'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_suspicious_ip_without_token_is_rejected(): void
    {
        $boardId = $this->insertBoard('turnstile-vote-2');
        $userId  = $this->insertUser('turnstile-vote-2@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $ip      = '203.0.113.2';
        $this->makeIpSuspicious($ip, 3);

        $response = $this->createApp(config: $this->turnstileConfig())
            ->handle($this->postVote('turnstile-vote-2', $ideaId, 'up', $userId, $ip));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('turnstile_required', $data['error']['key'] ?? null);
        self::assertSame('site-123', $data['error']['site_key'] ?? null);
        // No vote row was created.
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]));
    }

    public function test_suspicious_ip_with_valid_token_succeeds(): void
    {
        $boardId = $this->insertBoard('turnstile-vote-3');
        $userId  = $this->insertUser('turnstile-vote-3@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $ip      = '203.0.113.3';
        $this->makeIpSuspicious($ip, 3);

        $http = new InMemoryTurnstileHttpClient();
        $http->stubSuccess('good-token');

        $response = $this->createApp(config: $this->turnstileConfig(), turnstileHttpClient: $http)
            ->handle($this->postVote('turnstile-vote-3', $ideaId, 'up', $userId, $ip, 'good-token'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]));
    }

    public function test_suspicious_ip_with_invalid_token_is_rejected(): void
    {
        $boardId = $this->insertBoard('turnstile-vote-4');
        $userId  = $this->insertUser('turnstile-vote-4@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $ip      = '203.0.113.4';
        $this->makeIpSuspicious($ip, 3);

        $response = $this->createApp(config: $this->turnstileConfig(), turnstileHttpClient: new InMemoryTurnstileHttpClient())
            ->handle($this->postVote('turnstile-vote-4', $ideaId, 'up', $userId, $ip, 'bad-token'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_below_threshold_ip_needs_no_challenge(): void
    {
        $boardId = $this->insertBoard('turnstile-vote-5');
        $userId  = $this->insertUser('turnstile-vote-5@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $ip      = '203.0.113.5';
        $this->makeIpSuspicious($ip, 2); // below threshold=3

        $response = $this->createApp(config: $this->turnstileConfig())
            ->handle($this->postVote('turnstile-vote-5', $ideaId, 'up', $userId, $ip));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_suspicious_ip_is_unchallenged_when_turnstile_is_not_configured(): void
    {
        $boardId = $this->insertBoard('turnstile-vote-6');
        $userId  = $this->insertUser('turnstile-vote-6@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $ip      = '203.0.113.6';
        $this->makeIpSuspicious($ip, 99);

        // No config override → default testConfig() has no `turnstile` block (unconfigured).
        $response = $this->createApp()
            ->handle($this->postVote('turnstile-vote-6', $ideaId, 'up', $userId, $ip));

        self::assertSame(200, $response->getStatusCode());
    }
}
