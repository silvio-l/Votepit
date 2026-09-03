<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Http\AppFactory;

/**
 * Smoke test: proves boot, pipeline, JSON response, and security headers.
 * No DB needed (the smoke route is database-free).
 */
final class SmokeTest extends TestCase
{
    private function config(): Config
    {
        return Config::fromArray([
            'env'     => 'dev',
            'app_url' => 'http://localhost:8000',
            'app_key' => str_repeat('a', 64),
            'identity_server_key' => str_repeat('b', 64),
            'db'      => ['name' => 'votepit_test'],
            'smtp'    => ['from_email' => 'noreply@example.com'],
        ]);
    }

    public function test_home_responds_200_with_json_and_security_headers(): void
    {
        $app      = AppFactory::create($this->config());
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        // Security headers (security.md §3 — A05)
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringStartsWith('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringNotContainsString("unsafe-eval", $csp);

        // Body: JSON API response
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertTrue($data['ok'] ?? false);

        // CSRF synchronizer: the pipeline issues a signed cookie on GET.
        self::assertStringContainsString('votepit_csrf=', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_config_rejects_empty_app_key(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray([
            'env'     => 'dev',
            'app_url' => 'http://localhost:8000',
            'app_key' => '',
            'db'      => ['name' => 'votepit_test'],
            'smtp'    => ['from_email' => 'noreply@example.com'],
        ]);
    }

    public function test_config_rejects_invalid_app_url(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray([
            'env'     => 'dev',
            'app_url' => 'not-a-url',
            'app_key' => str_repeat('a', 64),
            'identity_server_key' => str_repeat('b', 64),
            'db'      => ['name' => 'votepit_test'],
            'smtp'    => ['from_email' => 'noreply@example.com'],
        ]);
    }
}
