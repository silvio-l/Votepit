<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Config;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RouteTable;

/**
 * Characterization test: pins the complete registered route table
 * (method, pattern, route name, handler, route-level middleware in
 * execution order incl. AuthZ level and rate-limit bucket) for both
 * routing modes as golden files under tests/Http/snapshots/.
 *
 * Any route added, removed, re-ordered or re-guarded shows up as a diff
 * here. Intended changes: regenerate with
 *   UPDATE_ROUTE_SNAPSHOTS=1 vendor/bin/phpunit --filter RouteTableSnapshotTest
 * and review the golden-file diff like code. The cloud layer has its own
 * snapshot (cloud/tests/RouteTableSnapshotTest.php) with CloudExtension.
 */
final class RouteTableSnapshotTest extends IntegrationTestCase
{
    public function test_self_host_route_table_matches_snapshot(): void
    {
        [$expected, $actual] = RouteTable::snapshot($this->createApp(), __DIR__ . '/snapshots/routes.self-host.txt');

        self::assertSame($expected, $actual);
    }

    public function test_cloud_mode_route_table_matches_snapshot(): void
    {
        $config = Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));

        [$expected, $actual] = RouteTable::snapshot($this->createApp(config: $config), __DIR__ . '/snapshots/routes.cloud-mode.txt');

        self::assertSame($expected, $actual);
    }
}
