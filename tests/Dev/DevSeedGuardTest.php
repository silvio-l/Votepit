<?php

declare(strict_types=1);

namespace Votepit\Tests\Dev;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Config;
use Votepit\Dev\DemoSeeder;
use Votepit\Dev\DevSeedGuard;
use Votepit\Dev\DevSeedRefusedException;

/**
 * The demo seed must refuse anything that is not a local development
 * installation. These tests pin each refusal condition.
 */
final class DevSeedGuardTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        return Config::fromArray(array_replace_recursive([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:5173',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => str_repeat('b', 64),
            'routing_mode'        => 'self-host',
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'db'                  => ['host' => '127.0.0.1', 'name' => 'votepit_dev', 'user' => 'u', 'pass' => 'p'],
        ], $overrides));
    }

    public function test_accepts_a_local_development_installation(): void
    {
        DevSeedGuard::assertLocalDevelopment($this->config());
        $this->expectNotToPerformAssertions();
    }

    public function test_accepts_ipv6_loopback_and_localhost_names(): void
    {
        DevSeedGuard::assertLocalDevelopment($this->config(['db' => ['host' => '::1']]));
        DevSeedGuard::assertLocalDevelopment($this->config(['db' => ['host' => 'localhost'], 'app_url' => 'http://127.0.0.1:8080']));
        $this->expectNotToPerformAssertions();
    }

    public function test_refuses_production_env(): void
    {
        $this->expectException(DevSeedRefusedException::class);
        DevSeedGuard::assertLocalDevelopment($this->config(['env' => 'prod']));
    }

    public function test_refuses_remote_database_host(): void
    {
        $this->expectException(DevSeedRefusedException::class);
        DevSeedGuard::assertLocalDevelopment($this->config(['db' => ['host' => 'db.example.com']]));
    }

    public function test_refuses_host_name_that_only_starts_with_localhost(): void
    {
        $this->expectException(DevSeedRefusedException::class);
        DevSeedGuard::assertLocalDevelopment($this->config(['db' => ['host' => 'localhost.example.com']]));
    }

    public function test_refuses_non_local_app_url(): void
    {
        $this->expectException(DevSeedRefusedException::class);
        DevSeedGuard::assertLocalDevelopment($this->config(['app_url' => 'https://app.example.com']));
    }

    public function test_refuses_cloud_routing(): void
    {
        $this->expectException(DevSeedRefusedException::class);
        DevSeedGuard::assertLocalDevelopment($this->config(['routing_mode' => 'cloud']));
    }

    public function test_refuses_remote_smtp_relay(): void
    {
        $this->expectException(DevSeedRefusedException::class);
        DevSeedGuard::assertLocalDevelopment($this->config(['smtp' => ['host' => 'smtp.example.com']]));
    }

    public function test_seeder_refuses_to_be_constructed_for_a_remote_config(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->expectException(DevSeedRefusedException::class);
        new DemoSeeder($this->config(['env' => 'prod']), $conn);
    }
}
