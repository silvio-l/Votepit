<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;

/**
 * Route-table introspection for a fully built Slim app.
 *
 * Lists every registered route in REGISTRATION order (which matters:
 * FastRoute rejects a static route registered after a colliding variable
 * route, see AppFactory's "Cloud-mode routing safety net") together with
 * its route-level middleware in EXECUTION order (outermost first).
 *
 * Used by RouteTableSnapshotTest (core + cloud) to pin the complete route
 * table as a golden file, and meant to be reused by any test that has to
 * walk every route — e.g. an "every route carries an AuthZ middleware"
 * coverage test can iterate RouteTable::routes() and inspect
 * RouteTable::middleware()/authzLevel() per route.
 *
 * Slim exposes no public getter for a route's middleware stack, so
 * middleware() walks the route's MiddlewareDispatcher chain via
 * reflection (tip → … → the Route itself). Test-only by design.
 */
final class RouteTable
{
    /**
     * @param App<null> $app
     * @return list<RouteInterface>
     */
    public static function routes(App $app): array
    {
        return array_values($app->getRouteCollector()->getRoutes());
    }

    /**
     * Route-level middleware instances, outermost (runs first) → innermost.
     *
     * @return list<object|string>
     */
    public static function middleware(RouteInterface $route): array
    {
        $dispatcherProp = new \ReflectionProperty($route, 'middlewareDispatcher');
        $dispatcher     = $dispatcherProp->getValue($route);
        if (!is_object($dispatcher)) {
            throw new \LogicException('Route has no middleware dispatcher.');
        }

        $tip = (new \ReflectionProperty($dispatcher, 'tip'))->getValue($dispatcher);

        $stack = [];
        while ($tip instanceof RequestHandlerInterface && $tip !== $route) {
            $ref = new \ReflectionObject($tip);
            if (!$ref->hasProperty('middleware') || !$ref->hasProperty('next')) {
                throw new \LogicException('Unexpected middleware link: ' . $ref->getName());
            }
            $mw = $ref->getProperty('middleware')->getValue($tip);
            if (!is_object($mw) && !is_string($mw)) {
                throw new \LogicException('Unexpected middleware value in route stack.');
            }
            $stack[] = $mw;
            $tip     = $ref->getProperty('next')->getValue($tip);
        }

        return $stack;
    }

    /**
     * AuthZ level (AuthZMiddleware::LEVEL_*) of the route's AuthZ
     * middleware, or null when the route carries none.
     */
    public static function authzLevel(RouteInterface $route): ?string
    {
        foreach (self::middleware($route) as $mw) {
            if ($mw instanceof AuthZMiddleware) {
                $level = (new \ReflectionProperty($mw, 'required'))->getValue($mw);
                return is_string($level) ? $level : null;
            }
        }

        return null;
    }

    /**
     * One stable, diff-friendly line per route:
     *   `GET|POST /pattern [name] -> Handler | Mw1, Mw2(detail)`
     *
     * @param App<null> $app
     * @return list<string>
     */
    public static function describe(App $app): array
    {
        $lines = [];
        foreach (self::routes($app) as $route) {
            $name  = $route->getName();
            $mws   = array_map(self::describeMiddleware(...), self::middleware($route));
            $lines[] = sprintf(
                '%s %s%s -> %s | %s',
                implode('|', $route->getMethods()),
                $route->getPattern(),
                $name !== null ? ' [' . $name . ']' : '',
                self::describeCallable($route->getCallable()),
                $mws === [] ? '-' : implode(', ', $mws),
            );
        }

        return $lines;
    }

    /**
     * Renders describe() as a golden-file body and returns
     * [expected, actual] for an assertSame(). With the environment
     * variable UPDATE_ROUTE_SNAPSHOTS=1 the golden file is (re)written
     * first (a missing file therefore fails until generated) — do that deliberately when you add/change a route and review
     * the resulting diff like code.
     *
     * @param App<null> $app
     * @return array{0: string, 1: string}
     */
    public static function snapshot(App $app, string $file): array
    {
        $actual = implode("\n", self::describe($app)) . "\n";

        if (getenv('UPDATE_ROUTE_SNAPSHOTS') === '1') {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $actual);
        }

        $expected = is_file($file) ? file_get_contents($file) : false;

        return [$expected === false ? '' : $expected, $actual];
    }

    private static function describeMiddleware(object|string $mw): string
    {
        if (is_string($mw)) {
            return $mw;
        }

        if ($mw instanceof AuthZMiddleware) {
            $level = (new \ReflectionProperty($mw, 'required'))->getValue($mw);
            return 'AuthZMiddleware(' . (is_string($level) ? $level : '?') . ')';
        }

        if ($mw instanceof RateLimitMiddleware) {
            $bucket = (new \ReflectionProperty($mw, 'bucketPrefix'))->getValue($mw);
            $limit  = (new \ReflectionProperty($mw, 'limit'))->getValue($mw);
            $window = (new \ReflectionProperty($mw, 'window'))->getValue($mw);
            return sprintf(
                'RateLimitMiddleware(%s %s/%s)',
                is_string($bucket) ? $bucket : '?',
                is_int($limit) ? (string) $limit : '?',
                is_int($window) ? (string) $window : '?',
            );
        }

        return self::className($mw);
    }

    private static function describeCallable(mixed $callable): string
    {
        if (is_string($callable)) {
            return $callable;
        }
        if (is_array($callable) && count($callable) === 2) {
            $target = $callable[0];
            $method = $callable[1];
            return (is_object($target) ? self::className($target) : (is_string($target) ? $target : '?'))
                . '::' . (is_string($method) ? $method : '?');
        }
        if (is_object($callable)) {
            return self::className($callable);
        }

        return '?';
    }

    /** Short class name; anonymous classes collapse to a stable label. */
    private static function className(object $object): string
    {
        $ref = new \ReflectionObject($object);
        if ($ref->isAnonymous()) {
            $parent     = $ref->getParentClass();
            $interfaces = $ref->getInterfaceNames();
            $base       = $parent !== false ? $parent->getShortName() : ($interfaces !== [] ? (new \ReflectionClass($interfaces[0]))->getShortName() : 'object');
            return 'anonymous@' . $base;
        }

        return $ref->getShortName();
    }
}
