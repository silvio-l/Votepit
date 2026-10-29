<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RouteAuthzCoverage;

/**
 * Deny-by-default AuthZ coverage over EVERY registered route (core,
 * both routing modes). A route passes only if it
 *   - carries an AuthZMiddleware with a non-anon level, or
 *   - carries AuthZMiddleware::anon() AND is listed in
 *     RouteAuthzCoverage::CORE_ANON_ROUTES (reviewed: genuinely public), or
 *   - carries no AuthZMiddleware at all AND is listed in
 *     RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES together with the substitute
 *     guard middleware it must carry instead (e.g. ApiTokenAuthMiddleware).
 *
 * The cloud app (core + CloudExtension) is covered by
 * cloud/tests/RouteAuthzCoverageTest.php with the same helper.
 * How to extend the allow-lists: core/documentation/development.md
 * ("Where routes live").
 */
final class RouteAuthzCoverageTest extends IntegrationTestCase
{
    public function test_every_self_host_route_is_authz_guarded_or_allow_listed(): void
    {
        $violations = RouteAuthzCoverage::violations(
            $this->createApp(),
            RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES,
            RouteAuthzCoverage::CORE_ANON_ROUTES,
        );

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_every_cloud_mode_route_is_authz_guarded_or_allow_listed(): void
    {
        $violations = RouteAuthzCoverage::violations(
            $this->createApp(config: $this->cloudModeConfig()),
            RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES,
            RouteAuthzCoverage::CORE_ANON_ROUTES,
        );

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_core_allow_lists_have_no_stale_entries(): void
    {
        $stale = RouteAuthzCoverage::staleEntries(
            [$this->createApp(), $this->createApp(config: $this->cloudModeConfig())],
            RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES,
            RouteAuthzCoverage::CORE_ANON_ROUTES,
        );

        self::assertSame([], $stale, implode("\n", $stale));
    }

    // -----------------------------------------------------------------
    // Meta-tests: the checker really catches unguarded routes.
    // -----------------------------------------------------------------

    public function test_meta_route_without_authz_and_not_allow_listed_is_a_violation(): void
    {
        $app = $this->createApp();
        $app->get('/__dummy-unguarded', $this->noopHandler(...));

        $violations = RouteAuthzCoverage::violations($app, RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES, RouteAuthzCoverage::CORE_ANON_ROUTES);

        self::assertCount(1, $violations);
        self::assertStringContainsString('GET /__dummy-unguarded', $violations[0]);
    }

    public function test_meta_anon_route_not_on_anon_allow_list_is_a_violation(): void
    {
        $app = $this->createApp();
        $app->post('/__dummy-anon', $this->noopHandler(...))
            ->add(AuthZMiddleware::anon(new ResponseFactory()));

        $violations = RouteAuthzCoverage::violations($app, RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES, RouteAuthzCoverage::CORE_ANON_ROUTES);

        self::assertCount(1, $violations);
        self::assertStringContainsString('POST /__dummy-anon', $violations[0]);
    }

    public function test_meta_route_with_non_anon_authz_passes(): void
    {
        $app = $this->createApp();
        $app->post('/__dummy-user', $this->noopHandler(...))
            ->add(AuthZMiddleware::user(new ResponseFactory()));

        self::assertSame([], RouteAuthzCoverage::violations($app, RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES, RouteAuthzCoverage::CORE_ANON_ROUTES));
    }

    public function test_meta_no_authz_entry_requires_its_substitute_guard(): void
    {
        $guard = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
        $noAuthz = ['GET /__dummy-guarded' => ['guard' => $guard::class, 'reason' => 'meta-test']];

        $withGuard = $this->createApp();
        $withGuard->get('/__dummy-guarded', $this->noopHandler(...))->add($guard);
        self::assertSame([], RouteAuthzCoverage::violations($withGuard, RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES + $noAuthz, RouteAuthzCoverage::CORE_ANON_ROUTES));

        $withoutGuard = $this->createApp();
        $withoutGuard->get('/__dummy-guarded', $this->noopHandler(...));
        $violations = RouteAuthzCoverage::violations($withoutGuard, RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES + $noAuthz, RouteAuthzCoverage::CORE_ANON_ROUTES);
        self::assertCount(1, $violations);
        self::assertStringContainsString('GET /__dummy-guarded', $violations[0]);
    }

    public function test_meta_unused_allow_list_entry_is_reported_stale(): void
    {
        $stale = RouteAuthzCoverage::staleEntries(
            [$this->createApp()],
            RouteAuthzCoverage::CORE_NO_AUTHZ_ROUTES,
            RouteAuthzCoverage::CORE_ANON_ROUTES + ['GET /__gone' => 'meta-test'],
        );

        self::assertContains('GET /__gone', $stale);
    }

    private function cloudModeConfig(): Config
    {
        return Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));
    }

    private function noopHandler(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response;
    }
}
