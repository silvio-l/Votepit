<?php

declare(strict_types=1);

namespace Votepit\Tests;

use PHPUnit\Framework\TestCase;
use Votepit\Config;
use Votepit\ConfigException;

/**
 * Config::routingMode / sessionCookieDomain (cloud path routing).
 */
final class ConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function baseArray(): array
    {
        return [
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => str_repeat('b', 64),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
        ];
    }

    public function test_routing_mode_defaults_to_self_host(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertSame('self-host', $config->routingMode);
        self::assertNull($config->sessionCookieDomain);
    }

    public function test_routing_mode_accepts_cloud(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'routing_mode' => 'cloud']);

        self::assertSame('cloud', $config->routingMode);
    }

    public function test_routing_mode_rejects_unknown_value(): void
    {
        $this->expectException(ConfigException::class);

        Config::fromArray([...$this->baseArray(), 'routing_mode' => 'multi-tenant']);
    }

    public function test_session_cookie_domain_defaults_to_null(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertNull($config->sessionCookieDomain);
    }

    public function test_session_cookie_domain_is_read_when_set(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'session_cookie_domain' => 'app.example.com']);

        self::assertSame('app.example.com', $config->sessionCookieDomain);
    }

    public function test_sentry_dsn_defaults_to_empty_string(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertSame('', $config->sentryDsn);
    }

    public function test_sentry_dsn_is_read_when_set(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'sentry_dsn' => 'https://key@o0.ingest.sentry.io/1']);

        self::assertSame('https://key@o0.ingest.sentry.io/1', $config->sentryDsn);
    }

    public function test_extensions_default_to_empty(): void
    {
        self::assertSame([], Config::fromArray($this->baseArray())->extensions);
    }

    public function test_extensions_are_read_as_class_to_options_map(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'extensions' => [
            'Vendor\\Ext\\SomeExtension' => ['api_key' => 'x'],
            'Vendor\\Ext\\Other'         => [],
        ]]);

        self::assertSame(['Vendor\\Ext\\SomeExtension' => ['api_key' => 'x'], 'Vendor\\Ext\\Other' => []], $config->extensions);
    }

    public function test_extensions_reject_non_array_options(): void
    {
        $this->expectException(ConfigException::class);

        Config::fromArray([...$this->baseArray(), 'extensions' => ['Vendor\\Ext\\SomeExtension' => 'yes']]);
    }

    public function test_extensions_reject_list_of_class_names(): void
    {
        $this->expectException(ConfigException::class);

        Config::fromArray([...$this->baseArray(), 'extensions' => ['Vendor\\Ext\\SomeExtension']]);
    }
}
