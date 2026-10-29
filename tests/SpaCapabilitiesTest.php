<?php

declare(strict_types=1);

namespace Votepit\Tests;

use PHPUnit\Framework\TestCase;
use Votepit\Config;
use Votepit\SpaCapabilities;

/**
 * SpaCapabilities::checkRoutingMode() — public/index.php's boot-time
 * self-check (2026-08-31 incident, see class doc-comment). Deliberately
 * separate from ConfigTest: this guard must NOT fire inside
 * Config::fromArray, since tests/Http/CloudRoutingTest.php constructs a
 * routing_mode: 'cloud' Config directly to exercise the (already correct,
 * fully working) backend cloud-mode routing.
 */
final class SpaCapabilitiesTest extends TestCase
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

    public function test_self_host_passes(): void
    {
        $config = Config::fromArray($this->baseArray());

        self::assertNull(SpaCapabilities::checkRoutingMode($config));
    }

    public function test_cloud_passes_now_that_the_spa_has_account_prefixed_routes(): void
    {
        $config = Config::fromArray([...$this->baseArray(), 'routing_mode' => 'cloud']);

        self::assertNull(SpaCapabilities::checkRoutingMode($config));
    }

    public function test_cloud_account_routing_ready_reports_true(): void
    {
        self::assertTrue(SpaCapabilities::cloudAccountRoutingReady());
    }
}
