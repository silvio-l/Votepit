<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Rate-limit integration test for the per-action bucket `report:submit`
 * (production-readiness audit — POST /reports was previously the only public,
 * unauthenticated write endpoint without a dedicated per-action limit; only
 * the coarse global per-IP limit applied).
 *
 * Overrides testConfig() with a low threshold (1/window), so the limit is
 * deterministically reachable. Also proves that the key `report:submit`
 * looked up in AppFactory matches the config key.
 */
final class RateLimitReportTest extends IntegrationTestCase
{
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => [
                'report:submit' => ['limit' => 1, 'window' => 3600],
            ],
        ]);
    }

    private function postReport(string $reason): ServerRequestInterface
    {
        $csrf   = new CsrfService(str_repeat('a', 64), 3600, false);
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/reports', ['REMOTE_ADDR' => '203.0.113.42'])
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody([
                '_csrf'  => $token,
                'url'    => '/demo/ideas/1',
                'reason' => $reason,
            ]);
    }

    public function test_exceeding_report_limit_returns_429(): void
    {
        $app = $this->createApp();

        // 1st report (count=1, 1 <= 1 → allowed → 201)
        $first = $app->handle($this->postReport('First report, worded at sufficient length.'));
        self::assertSame(201, $first->getStatusCode());

        // 2nd report from the same IP (count=2, 2 > 1 → 429)
        $second = $app->handle($this->postReport('Second report, worded at sufficient length.'));
        self::assertSame(429, $second->getStatusCode());

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM abuse_reports');
        self::assertSame(1, $count);
    }
}
