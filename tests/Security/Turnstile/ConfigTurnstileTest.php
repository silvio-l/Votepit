<?php

declare(strict_types=1);

namespace Votepit\Tests\Security\Turnstile;

use PHPUnit\Framework\TestCase;
use Votepit\Config;

/**
 * Config::turnstile*() accessors (OWASP/BSI audit remediation issue 20:
 * anti-automation challenge). Empty site_key/secret_key = feature entirely
 * inert (self-host default) — see Config::turnstileConfigured() class doc.
 */
final class ConfigTurnstileTest extends TestCase
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

    public function test_turnstile_defaults_to_unconfigured(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertFalse($config->turnstileConfigured());
        self::assertSame('', $config->turnstileSiteKey());
        self::assertSame('', $config->turnstileSecretKey());
        self::assertSame(3, $config->turnstileSuspicionThreshold());
        self::assertSame(3600, $config->turnstileSuspicionWindowSeconds());
    }

    public function test_turnstile_is_configured_only_when_both_keys_are_set(): void
    {
        $siteKeyOnly = Config::fromArray([...$this->baseArray(), 'turnstile' => ['site_key' => 'site-123']]);
        self::assertFalse($siteKeyOnly->turnstileConfigured());

        $secretKeyOnly = Config::fromArray([...$this->baseArray(), 'turnstile' => ['secret_key' => 'secret-456']]);
        self::assertFalse($secretKeyOnly->turnstileConfigured());

        $both = Config::fromArray([...$this->baseArray(), 'turnstile' => ['site_key' => 'site-123', 'secret_key' => 'secret-456']]);
        self::assertTrue($both->turnstileConfigured());
    }

    public function test_turnstile_keys_are_trimmed(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'turnstile' => [
            'site_key'   => '  site-123  ',
            'secret_key' => "secret-456\n",
        ]]);

        self::assertSame('site-123', $config->turnstileSiteKey());
        self::assertSame('secret-456', $config->turnstileSecretKey());
    }

    public function test_turnstile_suspicion_threshold_and_window_are_read_when_set(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'turnstile' => [
            'site_key'                 => 'site-123',
            'secret_key'               => 'secret-456',
            'suspicion_threshold'      => 5,
            'suspicion_window_seconds' => 120,
        ]]);

        self::assertSame(5, $config->turnstileSuspicionThreshold());
        self::assertSame(120, $config->turnstileSuspicionWindowSeconds());
    }
}
