<?php

declare(strict_types=1);

namespace Votepit\Tests;

use PHPUnit\Framework\TestCase;
use Votepit\Config;

/**
 * Config::translationConfigured()/translationProvider() — fail-secure
 * guard for the on-demand translation feature (mirrors
 * ConfigOAuthTest-style coverage of oauthProviderConfigured()).
 */
final class ConfigTranslationTest extends TestCase
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

    public function test_fresh_checkout_with_no_config_change_is_disabled(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertFalse($config->translationConfigured());
        self::assertSame('', $config->translationProvider());
    }

    public function test_provider_set_but_no_google_config_stays_disabled(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'translation' => ['provider' => 'google']]);

        self::assertFalse($config->translationConfigured());
    }

    public function test_missing_project_id_stays_disabled(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'translation' => [
            'provider' => 'google',
            'google'   => ['project_id' => '', 'service_account_key' => '/tmp/key.json'],
        ]]);

        self::assertFalse($config->translationConfigured());
    }

    public function test_missing_service_account_key_stays_disabled(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'translation' => [
            'provider' => 'google',
            'google'   => ['project_id' => 'proj', 'service_account_key' => ''],
        ]]);

        self::assertFalse($config->translationConfigured());
    }

    public function test_unknown_provider_is_ignored(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'translation' => [
            'provider' => 'deepl',
            'google'   => ['project_id' => 'proj', 'service_account_key' => '/tmp/key.json'],
        ]]);

        self::assertFalse($config->translationConfigured());
    }

    public function test_fully_configured_google_is_enabled(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'translation' => [
            'provider' => 'google',
            'google'   => ['project_id' => 'proj', 'service_account_key' => '/tmp/key.json', 'region' => 'eu'],
        ]]);

        self::assertTrue($config->translationConfigured());
        self::assertSame('google', $config->translationProvider());
        self::assertSame('proj', $config->translationGoogleConfig()['project_id']);
        self::assertSame('eu', $config->translationGoogleConfig()['region']);
    }

    public function test_region_defaults_to_eu(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'translation' => [
            'provider' => 'google',
            'google'   => ['project_id' => 'proj', 'service_account_key' => '/tmp/key.json'],
        ]]);

        self::assertSame('eu', $config->translationGoogleConfig()['region']);
    }
}
