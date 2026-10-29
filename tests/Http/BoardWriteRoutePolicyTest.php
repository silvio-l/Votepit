<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Config;
use Votepit\Tests\Support\BoardWriteRoutePolicy;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RouteTable;

/**
 * Route-table-driven policy test for frozen boards (P-21).
 *
 * Iterates EVERY registered write route of the self-host and the cloud-mode
 * core app and requires each one to be classified in BoardWriteRoutePolicy
 * as GUARDED (FrozenBoardGuard in the handler), EXEMPT (documented hole) or
 * ACCOUNT (not board-scoped). A new write route without a classification
 * fails here; a stale classification (route removed or renamed) fails too.
 * Board-scoped paths may never be classified ACCOUNT.
 *
 * Behavioural proof that each GUARDED route really answers 423 lives in
 * FrozenBoardWriteMatrixTest; this test guarantees nothing slips past the
 * classification.
 */
final class BoardWriteRoutePolicyTest extends IntegrationTestCase
{
    /**
     * @param \Slim\App<null> $app
     * @return array<string, string> key => route description (method pattern)
     */
    private function writeKeysOf(\Slim\App $app): array
    {
        $keys = [];
        foreach (BoardWriteRoutePolicy::writeRoutes(...RouteTable::routes($app)) as $route) {
            $keys[BoardWriteRoutePolicy::keyOf($route)] = implode('|', $route->getMethods()) . ' ' . $route->getPattern();
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function selfHostWriteKeys(): array
    {
        return $this->writeKeysOf($this->createApp());
    }

    /** @return array<string, string> */
    private function cloudModeWriteKeys(): array
    {
        $config = Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));

        return $this->writeKeysOf($this->createApp(config: $config));
    }

    public function test_every_self_host_write_route_is_classified(): void
    {
        $policy  = BoardWriteRoutePolicy::entries();
        $missing = array_diff_key($this->selfHostWriteKeys(), $policy);

        self::assertSame([], array_values($missing), 'Write routes without a frozen-board classification (add to BoardWriteRoutePolicy).');
    }

    public function test_every_cloud_mode_core_write_route_is_classified(): void
    {
        $policy  = BoardWriteRoutePolicy::entries();
        $missing = array_diff_key($this->cloudModeWriteKeys(), $policy);

        self::assertSame([], array_values($missing), 'Write routes without a frozen-board classification (add to BoardWriteRoutePolicy).');
    }

    public function test_no_stale_classification_for_a_removed_route(): void
    {
        $registered = $this->selfHostWriteKeys() + $this->cloudModeWriteKeys();
        $stale      = array_diff_key(BoardWriteRoutePolicy::entries(), $registered);

        self::assertSame([], array_keys($stale), 'Policy entries that no core write route matches any more.');
    }

    public function test_every_entry_has_a_class_and_a_justification(): void
    {
        $valid = [BoardWriteRoutePolicy::GUARDED, BoardWriteRoutePolicy::EXEMPT, BoardWriteRoutePolicy::ACCOUNT];

        foreach (BoardWriteRoutePolicy::entries() as $key => [$class, $detail]) {
            self::assertContains($class, $valid, "Unknown class for {$key}");
            self::assertNotSame('', trim($detail), "Empty justification for {$key}");
        }
    }

    public function test_board_scoped_paths_are_never_classified_as_account(): void
    {
        $offenders = [];
        foreach (BoardWriteRoutePolicy::entries() as $key => [$class]) {
            if (BoardWriteRoutePolicy::isBoardScoped($key) && $class === BoardWriteRoutePolicy::ACCOUNT) {
                $offenders[] = $key;
            }
        }

        self::assertSame([], $offenders, 'A board-scoped write must be GUARDED or EXEMPT with a reason, never ACCOUNT.');
    }

    public function test_guarded_routes_are_board_scoped(): void
    {
        $offenders = [];
        foreach (BoardWriteRoutePolicy::entries() as $key => [$class]) {
            if ($class === BoardWriteRoutePolicy::GUARDED && !BoardWriteRoutePolicy::isBoardScoped($key)) {
                $offenders[] = $key;
            }
        }

        self::assertSame([], $offenders, 'Only board-scoped routes can carry a FrozenBoardGuard.');
    }
}
