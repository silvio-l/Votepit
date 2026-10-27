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

    public function test_mandatory_rate_limit_falls_back_to_its_own_default_when_unconfigured(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertSame(['limit' => 10, 'window' => 3600], $config->mandatoryRateLimit('idea:submit:anon:ip', 10, 3600));
    }

    public function test_mandatory_rate_limit_honours_a_positive_override_but_never_zero(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'rate_limits' => [
            'idea:submit:anon:ip'    => ['limit' => 20, 'window' => 600],
            'idea:submit:anon:token' => ['limit' => 0, 'window' => 3600],
        ]]);

        self::assertSame(['limit' => 20, 'window' => 600], $config->mandatoryRateLimit('idea:submit:anon:ip', 10, 3600));
        self::assertSame(['limit' => 3, 'window' => 3600], $config->mandatoryRateLimit('idea:submit:anon:token', 3, 3600));
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

    public function test_guest_submission_kill_switch_defaults_on_for_self_host_and_off_for_cloud(): void
    {
        self::assertTrue(Config::fromArray($this->baseArray())->guestSubmissionEnabled);
        // Fail-secure: a hosted installation that never set the key stays locked.
        self::assertFalse(Config::fromArray([...$this->baseArray(), 'routing_mode' => 'cloud'])->guestSubmissionEnabled);
    }

    public function test_guest_submission_kill_switch_is_explicitly_configurable(): void
    {
        self::assertTrue(Config::fromArray([...$this->baseArray(), 'routing_mode' => 'cloud', 'guest_submission_enabled' => true])->guestSubmissionEnabled);
        self::assertFalse(Config::fromArray([...$this->baseArray(), 'guest_submission_enabled' => false])->guestSubmissionEnabled);
    }

    public function test_installation_kill_switches_default_on_and_accept_an_explicit_off(): void
    {
        $config = Config::fromArray($this->baseArray());
        self::assertTrue($config->boardUploadsEnabled);
        self::assertTrue($config->participantMailsEnabled);
        self::assertTrue($config->voteReasonsEnabled);

        $off = Config::fromArray([...$this->baseArray(), 'routing_mode' => 'cloud', 'board_uploads' => false, 'participant_mails' => false, 'vote_reasons' => false]);
        self::assertFalse($off->boardUploadsEnabled);
        self::assertFalse($off->participantMailsEnabled);
        self::assertFalse($off->voteReasonsEnabled);
    }

    public function test_installation_kill_switches_reject_non_booleans(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray([...$this->baseArray(), 'participant_mails' => 'off']);
    }

    public function test_guest_submission_kill_switch_rejects_non_booleans(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray([...$this->baseArray(), 'routing_mode' => 'cloud', 'guest_submission_enabled' => 'yes']);
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

    public function test_sentry_dsn_frontend_defaults_to_empty_string(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertSame('', $config->sentryDsnFrontend);
    }

    public function test_sentry_dsn_frontend_is_read_when_set(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'sentry_dsn_frontend' => 'https://key@o0.ingest.sentry.io/2']);

        self::assertSame('https://key@o0.ingest.sentry.io/2', $config->sentryDsnFrontend);
    }

    public function test_matomo_url_and_site_id_default_to_empty_string(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertSame('', $config->matomoUrl);
        self::assertSame('', $config->matomoSiteId);
    }

    public function test_matomo_url_and_site_id_are_read_when_set(): void
    {
        $config = Config::fromArray([
            ...$this->baseArray(),
            'matomo_url'     => 'https://matomo.example.com/',
            'matomo_site_id' => '10',
        ]);

        // Trailing slash stripped, same as app_url — a tracker URL is always
        // joined with a leading '/' path (see lib/analytics.ts loadTracker()).
        self::assertSame('https://matomo.example.com', $config->matomoUrl);
        self::assertSame('10', $config->matomoSiteId);
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

    public function test_extension_keys_are_normalized_to_the_class_constant_form(): void
    {
        // A var_export()-rewritten config carries '\\Vendor\\...' keys; consumers
        // look extensions up by SomeExtension::class (no leading backslash).
        $config = Config::fromArray([...$this->baseArray(), 'extensions' => [
            '\\Vendor\\Ext\\SomeExtension' => ['api_key' => 'x'],
        ]]);

        self::assertSame(['Vendor\\Ext\\SomeExtension' => ['api_key' => 'x']], $config->extensions);
    }

    public function test_extensions_reject_duplicate_keys_after_normalization(): void
    {
        $this->expectException(ConfigException::class);

        Config::fromArray([...$this->baseArray(), 'extensions' => [
            '\\Vendor\\Ext\\SomeExtension' => [],
            'Vendor\\Ext\\SomeExtension'   => [],
        ]]);
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

    public function test_readiness_cron_max_age_defaults_to_5_minutes_for_the_minute_tick(): void
    {
        self::assertSame(5, Config::fromArray($this->baseArray())->readinessCronMaxAgeMinutes);
    }

    public function test_readiness_cron_max_age_is_read_when_set(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'readiness_cron_max_age_minutes' => 45]);

        self::assertSame(45, $config->readinessCronMaxAgeMinutes);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCronMaxAges(): iterable
    {
        yield 'zero'     => [0];
        yield 'negative' => [-5];
        yield 'string'   => ['5'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCronMaxAges')]
    public function test_readiness_cron_max_age_rejects_non_positive_integers(mixed $value): void
    {
        $this->expectException(ConfigException::class);

        Config::fromArray([...$this->baseArray(), 'readiness_cron_max_age_minutes' => $value]);
    }
}
