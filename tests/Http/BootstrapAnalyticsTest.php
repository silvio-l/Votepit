<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET /api/bootstrap passes Config::matomoUrl/matomoSiteId through verbatim
 * (own analytics, same "public, authorizes sending only" reasoning as
 * sentry_dsn_frontend — see BootstrapSentryDsnTest), and exposes the
 * self-host-only product-improvement telemetry state. Telemetry is off unless
 * the operator configures telemetry_matomo_url AND telemetry_matomo_site_id,
 * and even then sends nothing before an explicit opt-in — see
 * TelemetryOptInAction and documentation/installation.md#product-telemetry.
 */
final class BootstrapAnalyticsTest extends IntegrationTestCase
{
    private const TELEMETRY_URL = 'https://telemetry.example.org';
    private const TELEMETRY_SITE = '42';

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
        $data = $this->bootstrapData(Config::fromArray([...$this->baseArray(), 'matomo_url' => 'https://matomo.example.com', 'matomo_site_id' => '10']));

        self::assertSame('https://matomo.example.com', $data['matomo_url'] ?? null);
        self::assertSame('10', $data['matomo_site_id'] ?? null);
    }

    public function test_telemetry_is_null_by_default_so_nothing_is_offered_or_loaded(): void
    {
        $data = $this->bootstrapData(null);

        self::assertArrayHasKey('telemetry', $data);
        self::assertNull($data['telemetry']);
    }

    public function test_telemetry_is_null_when_only_the_url_is_configured(): void
    {
        $data = $this->bootstrapData(Config::fromArray([...$this->baseArray(), 'telemetry_matomo_url' => self::TELEMETRY_URL]));

        self::assertNull($data['telemetry']);
    }

    public function test_telemetry_undecided_is_not_opted_in_even_when_configured(): void
    {
        // Migration 0035's column default is 1, but without an explicit decision
        // nothing may be sent: consent is opt-in.
        $data = $this->bootstrapData($this->telemetryConfig());

        self::assertIsArray($data['telemetry'] ?? null);
        self::assertFalse($data['telemetry']['decided']);
        self::assertFalse($data['telemetry']['opted_in']);
        self::assertSame('', $data['telemetry']['matomo_url']);
        self::assertSame('', $data['telemetry']['matomo_site_id']);
    }

    public function test_telemetry_reports_target_only_after_explicit_accept(): void
    {
        $this->conn->update(
            'accounts',
            ['telemetry_opted_in' => 1, 'telemetry_decided_at' => '2020-01-01 00:00:00'],
            ['id' => $this->defaultAccountId()],
        );

        $data = $this->bootstrapData($this->telemetryConfig());

        self::assertTrue($data['telemetry']['decided']);
        self::assertTrue($data['telemetry']['opted_in']);
        self::assertSame(self::TELEMETRY_URL, $data['telemetry']['matomo_url']);
        self::assertSame(self::TELEMETRY_SITE, $data['telemetry']['matomo_site_id']);
    }

    public function test_bootstrap_reports_opted_out_telemetry_state(): void
    {
        $this->conn->update(
            'accounts',
            ['telemetry_opted_in' => 0, 'telemetry_decided_at' => '2020-01-01 00:00:00'],
            ['id' => $this->defaultAccountId()],
        );

        $data = $this->bootstrapData($this->telemetryConfig());

        self::assertFalse($data['telemetry']['opted_in']);
        self::assertTrue($data['telemetry']['decided']);
        self::assertSame('', $data['telemetry']['matomo_url']);
    }

    public function test_bootstrap_reports_no_telemetry_state_in_cloud_mode(): void
    {
        $data = $this->bootstrapData(Config::fromArray([
            ...$this->baseArray(),
            'routing_mode' => 'cloud',
            'telemetry_matomo_url' => self::TELEMETRY_URL,
            'telemetry_matomo_site_id' => self::TELEMETRY_SITE,
        ]));

        self::assertArrayHasKey('telemetry', $data);
        self::assertNull($data['telemetry']);
    }

    public function test_csp_allows_no_third_party_tracker_origin_by_default(): void
    {
        $csp = $this->cspOf(null);

        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringContainsString("connect-src 'self';", $csp);
        self::assertStringNotContainsString('matomo', $csp);
        self::assertStringNotContainsString(self::TELEMETRY_URL, $csp);
    }

    public function test_csp_adds_only_the_configured_tracker_origin(): void
    {
        $csp = $this->cspOf(Config::fromArray([...$this->baseArray(), 'matomo_url' => 'https://analytics.example.org/piwik', 'matomo_site_id' => '3']));

        self::assertStringContainsString("script-src 'self' https://analytics.example.org;", $csp);
        self::assertStringContainsString("connect-src 'self' https://analytics.example.org;", $csp);
        self::assertStringNotContainsString(self::TELEMETRY_URL, $csp);
    }

    /** @return array<string, mixed> */
    private function baseArray(): array
    {
        return [
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
        ];
    }

    private function telemetryConfig(): Config
    {
        return Config::fromArray([
            ...$this->baseArray(),
            'telemetry_matomo_url'     => self::TELEMETRY_URL,
            'telemetry_matomo_site_id' => self::TELEMETRY_SITE,
        ]);
    }

    /** @return array<string, mixed> */
    private function bootstrapData(?Config $config): array
    {
        $response = $this->createApp(config: $config)->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        return json_decode((string) $response->getBody(), true);
    }

    private function cspOf(?Config $config): string
    {
        $response = $this->createApp(config: $config)->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/bootstrap'),
        );

        return $response->getHeaderLine('Content-Security-Policy');
    }
}
