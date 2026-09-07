<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET /api/bootstrap passes Config::sentryDsnFrontend through verbatim, so
 * the SPA can call initSentryFrontend() with a real DSN once one is set in
 * production config (release-hardening issue 07a — the DSN plumbing itself,
 * not the actual production secret, which is an ops-only step).
 */
final class BootstrapSentryDsnTest extends IntegrationTestCase
{
    public function test_bootstrap_reports_empty_sentry_dsn_frontend_by_default(): void
    {
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('', $data['sentry_dsn_frontend'] ?? null);
    }

    public function test_bootstrap_reports_configured_sentry_dsn_frontend(): void
    {
        $config = Config::fromArray([
            'env'                  => 'dev',
            'app_url'              => 'http://localhost:8000',
            'app_key'              => str_repeat('a', 64),
            'identity_server_key'  => self::identityServerKey(),
            'db'                   => ['name' => ':memory:'],
            'smtp'                 => ['from_email' => 'noreply@example.com'],
            'sentry_dsn_frontend'  => 'https://examplekey@o0.ingest.sentry.io/42',
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('https://examplekey@o0.ingest.sentry.io/42', $data['sentry_dsn_frontend'] ?? null);
    }
}
