<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Regression test for review 2026-09-04, item 1: `RateLimitMiddleware::perIp()`
 * used to derive a hardcoded `bucket = "ip:<addr>"` regardless of caller, so
 * the global DoS brake, `/login/password` and `/login/2fa` all shared one DB
 * row — the more-frequent global window silently overwrote the tighter
 * login windows on every hit. `perIp()` now takes an `$action` that
 * namespaces the bucket ("<action>:ip:<addr>"), so distinct actions against
 * the same IP track fully independently.
 *
 * Exercises RateLimitMiddleware/RateLimiter directly (not the full HTTP
 * app) — deterministic and independent of wall-clock window expiry.
 */
final class RateLimitBucketIsolationTest extends IntegrationTestCase
{
    private function allowHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    public function test_two_actions_against_the_same_ip_track_independently(): void
    {
        $limiter = new RateLimiter($this->conn);
        $rf      = new ResponseFactory();
        $ip      = '198.51.100.7';

        // Tight action: limit=1, window=3600 — the "global brake" analogue.
        $tight = RateLimitMiddleware::perIp($limiter, $rf, 'global', 1, 3600);
        // Looser, longer-window action — the "login:password" analogue.
        $loose = RateLimitMiddleware::perIp($limiter, $rf, 'login:password', 10, 900);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/whatever', ['REMOTE_ADDR' => $ip]);

        // Exhaust the tight bucket (2 hits: 1st allowed, 2nd exceeds limit=1).
        self::assertSame(200, $tight->process($request, $this->allowHandler())->getStatusCode());
        self::assertSame(429, $tight->process($request, $this->allowHandler())->getStatusCode());

        // The loose action's own bucket for the SAME IP is untouched by the
        // tight bucket's hits — before the fix, both wrote the same "ip:<addr>"
        // row, so this would already be exhausted too.
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(200, $loose->process($request, $this->allowHandler())->getStatusCode());
        }
        self::assertSame(429, $loose->process($request, $this->allowHandler())->getStatusCode());

        // Rows are stored under distinct, action-namespaced bucket keys.
        $buckets = $this->conn->fetchFirstColumn('SELECT bucket FROM rate_limits ORDER BY bucket');
        self::assertSame(['global:ip:' . $ip, 'login:password:ip:' . $ip], $buckets);
    }

    public function test_resetting_one_actions_bucket_does_not_affect_another_actions_bucket(): void
    {
        $limiter = new RateLimiter($this->conn);
        $rf      = new ResponseFactory();
        $ip      = '198.51.100.8';

        $password = RateLimitMiddleware::perIp($limiter, $rf, 'login:password', 3, 900);
        $twoFa    = RateLimitMiddleware::perIp($limiter, $rf, 'login:2fa', 3, 900);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/whatever', ['REMOTE_ADDR' => $ip]);

        // Exhaust both buckets independently.
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(200, $password->process($request, $this->allowHandler())->getStatusCode());
            self::assertSame(200, $twoFa->process($request, $this->allowHandler())->getStatusCode());
        }
        self::assertSame(429, $password->process($request, $this->allowHandler())->getStatusCode());
        self::assertSame(429, $twoFa->process($request, $this->allowHandler())->getStatusCode());

        // Simulates LoginPasswordAction::resetIpRateLimit() — only its own
        // "login:password:ip:<addr>" bucket, never "login:2fa:ip:<addr>".
        $limiter->reset('login:password:ip:' . $ip);

        self::assertSame(200, $password->process($request, $this->allowHandler())->getStatusCode());
        self::assertSame(429, $twoFa->process($request, $this->allowHandler())->getStatusCode());
    }
}
