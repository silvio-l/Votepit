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
 * audit remediation issue 20) on POST /{board}/ideas/{id}/comments. See
 * VoteTurnstileTest for the shared rationale.
 */
final class CommentCreateTurnstileTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postComment(string $slug, int $ideaId, string $body, int $userId, string $ip, ?string $turnstileToken = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $parsed = ['_csrf' => $token, 'body' => $body];
        if ($turnstileToken !== null) {
            $parsed['turnstile_token'] = $turnstileToken;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/comments', ['REMOTE_ADDR' => $ip])
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody($parsed);
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
        $boardId = $this->insertBoard('turnstile-comment-1');
        $userId  = $this->insertUser('turnstile-comment-1@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $response = $this->createApp(config: $this->turnstileConfig())
            ->handle($this->postComment('turnstile-comment-1', $ideaId, 'A normal comment.', $userId, '203.0.113.21'));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_suspicious_ip_without_token_is_rejected(): void
    {
        $boardId = $this->insertBoard('turnstile-comment-2');
        $userId  = $this->insertUser('turnstile-comment-2@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $ip      = '203.0.113.22';
        $this->makeIpSuspicious($ip, 3);

        $response = $this->createApp(config: $this->turnstileConfig())
            ->handle($this->postComment('turnstile-comment-2', $ideaId, 'A normal comment.', $userId, $ip));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('turnstile_required', $data['error']['key'] ?? null);
        self::assertSame('site-123', $data['error']['site_key'] ?? null);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $ideaId]));
    }

    public function test_suspicious_ip_with_valid_token_succeeds(): void
    {
        $boardId = $this->insertBoard('turnstile-comment-3');
        $userId  = $this->insertUser('turnstile-comment-3@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);
        $ip      = '203.0.113.23';
        $this->makeIpSuspicious($ip, 3);

        $http = new InMemoryTurnstileHttpClient();
        $http->stubSuccess('good-token');

        $response = $this->createApp(config: $this->turnstileConfig(), turnstileHttpClient: $http)
            ->handle($this->postComment('turnstile-comment-3', $ideaId, 'A normal comment.', $userId, $ip, 'good-token'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id', ['id' => $ideaId]));
    }
}
