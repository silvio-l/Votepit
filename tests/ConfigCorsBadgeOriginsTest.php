<?php

declare(strict_types=1);

namespace Votepit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Config;
use Votepit\ConfigException;

/**
 * Config::corsBadgeOrigins (cors_badge_origins) — the exact-origin allowlist
 * for the cross-origin session badge. Fail-fast on anything that is not one
 * plain https origin.
 */
final class ConfigCorsBadgeOriginsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function base(): array
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

    public function test_defaults_to_empty_allowlist(): void
    {
        self::assertSame([], Config::fromArray($this->base())->corsBadgeOrigins);
    }

    public function test_accepts_exact_https_origins_and_dedupes(): void
    {
        $config = Config::fromArray($this->base() + [
            'cors_badge_origins' => ['https://votepit.com', 'https://dev.votepit-web.pages.dev:8443', 'https://votepit.com'],
        ]);

        self::assertSame(['https://votepit.com', 'https://dev.votepit-web.pages.dev:8443'], $config->corsBadgeOrigins);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidEntries(): iterable
    {
        yield 'wildcard' => ['*'];
        yield 'trailing slash' => ['https://votepit.com/'];
        yield 'path' => ['https://votepit.com/app'];
        yield 'plain http' => ['http://votepit.com'];
        yield 'localhost' => ['https://localhost:4321'];
        yield 'uppercase host' => ['https://VotePit.com'];
        yield 'suffix trick' => ['https://votepit.com.evil.example/'];
        yield 'non-string' => [42];
        yield 'nested list' => [['https://votepit.com']];
    }

    #[DataProvider('invalidEntries')]
    public function test_rejects_anything_but_a_plain_https_origin(mixed $entry): void
    {
        $this->expectException(ConfigException::class);

        Config::fromArray($this->base() + ['cors_badge_origins' => [$entry]]);
    }
}
