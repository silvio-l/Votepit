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
 * GET /api/bootstrap passes Config::matomoUrl/matomoSiteId through verbatim
 * (own analytics, same "public, authorizes sending only" reasoning as
 * sentry_dsn_frontend — see BootstrapSentryDsnTest), and exposes the
 * self-host-only product-improvement telemetry state derived from the
 * default account's `telemetry_opted_in`/`telemetry_decided_at` columns —
 * see TelemetryOptInAction, Votepit\Telemetry\CommunityTelemetry.
 */
final class BootstrapAnalyticsTest extends IntegrationTestCase
{
    public function test_bootstrap_reports_empty_matomo_config_by_default(): void
    {
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('', $data['matomo_url'] ?? null);
        self::assertSame('', $data['matomo_site_id'] ?? null);
    }

    public function test_bootstrap_reports_configured_matomo_url_and_site_id(): void
    {
        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'matomo_url'          => 'https://matomo.example.com',
            'matomo_site_id'      => '10',
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('https://matomo.example.com', $data['matomo_url'] ?? null);
        self::assertSame('10', $data['matomo_site_id'] ?? null);
    }

    public function test_bootstrap_reports_telemetry_state_in_self_host_mode(): void
    {
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data['telemetry'] ?? null);
        // Default account is opted in (migration 0035 default) but has never
        // touched the toggle — see AccountRepository::setTelemetryDecision().
        self::assertTrue($data['telemetry']['opted_in']);
        self::assertFalse($data['telemetry']['decided']);
        self::assertSame('11', $data['telemetry']['matomo_site_id']);
    }

    public function test_bootstrap_reports_no_telemetry_state_in_cloud_mode(): void
    {
        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'routing_mode'        => 'cloud',
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('telemetry', $data);
        self::assertNull($data['telemetry']);
    }

    public function test_bootstrap_reports_opted_out_telemetry_state(): void
    {
        $this->conn->update(
            'accounts',
            ['telemetry_opted_in' => 0, 'telemetry_decided_at' => '2020-01-01 00:00:00'],
            ['id' => $this->defaultAccountId()],
        );

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['telemetry']['opted_in']);
        self::assertTrue($data['telemetry']['decided']);
    }
}
