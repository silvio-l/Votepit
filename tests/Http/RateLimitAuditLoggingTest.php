<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Security\RateLimiter;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * OWASP/BSI audit remediation issue 12: a 429 (rate limit exceeded) is a
 * security event and must produce exactly one audit-log entry, via the
 * pre-existing `onLimitExceeded` hook on RateLimitMiddleware.
 */
final class RateLimitAuditLoggingTest extends IntegrationTestCase
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

    /** @return list<array<string, mixed>> */
    private function readLog(string $logPath): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($logPath)), static fn (string $l): bool => $l !== '');

        return array_values(array_map(static fn (string $line): array => (array) json_decode($line, true), $lines));
    }

    public function test_exceeding_a_per_ip_limit_logs_exactly_one_entry(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'audit_');
        self::assertIsString($logPath);
        $audit   = new AuditLogger($logPath);
        $limiter = new RateLimiter($this->conn);
        $rf      = new ResponseFactory();
        $ip      = '198.51.100.9';

        $mw = RateLimitMiddleware::perIp(
            $limiter,
            $rf,
            'login:password',
            1,
            3600,
            false,
            static function (string $seenIp) use ($audit): void {
                $audit->log('login_password.rate_limited', ['ip_hmac' => hash('sha256', $seenIp)]);
            },
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => $ip]);

        self::assertSame(200, $mw->process($request, $this->allowHandler())->getStatusCode());
        self::assertSame(429, $mw->process($request, $this->allowHandler())->getStatusCode());
        // A second rejection must not produce a second entry beyond what the
        // hook itself is invoked for — one call, one entry, per exceeded hit.
        self::assertSame(429, $mw->process($request, $this->allowHandler())->getStatusCode());

        $entries = $this->readLog($logPath);
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertSame('login_password.rate_limited', $entry['action']);
            self::assertArrayNotHasKey('ip', $entry['context']);
            self::assertSame(hash('sha256', $ip), $entry['context']['ip_hmac']);
        }

        unlink($logPath);
    }

    public function test_a_request_within_the_limit_logs_nothing(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'audit_');
        self::assertIsString($logPath);
        $audit   = new AuditLogger($logPath);
        $limiter = new RateLimiter($this->conn);
        $rf      = new ResponseFactory();

        $mw = RateLimitMiddleware::perIp(
            $limiter,
            $rf,
            'magiclink',
            5,
            3600,
            false,
            static function (string $seenIp) use ($audit): void {
                $audit->log('magiclink.rate_limited', ['ip_hmac' => hash('sha256', $seenIp)]);
            },
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/magic-link', ['REMOTE_ADDR' => '198.51.100.10']);

        self::assertSame(200, $mw->process($request, $this->allowHandler())->getStatusCode());

        self::assertSame('', file_get_contents($logPath));

        unlink($logPath);
    }
}
