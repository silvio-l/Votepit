<?php

declare(strict_types=1);

namespace Votepit\Extension;

use Psr\Http\Server\MiddlewareInterface;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;

/**
 * Instantiates the extensions declared under Config::$extensions.
 *
 * Fail-fast: an unknown class, a class that does not implement
 * AppExtension, or two extensions competing for the plan policy are
 * configuration errors and abort the boot — never a silently degraded app.
 */
final class ExtensionLoader
{
    /** @return list<AppExtension> */
    public static function fromConfig(Config $config): array
    {
        $extensions = [];

        foreach ($config->extensions as $class => $options) {
            if (!class_exists($class)) {
                throw new ConfigException("config: extension class \"{$class}\" not found — is its autoloader required in config/config.php?");
            }
            if (!is_subclass_of($class, AppExtension::class)) {
                throw new ConfigException("config: extension class \"{$class}\" does not implement " . AppExtension::class);
            }

            $extensions[] = $class::fromOptions($options);
        }

        return $extensions;
    }

    /**
     * The single plan policy contributed by $extensions, or null if none
     * contributes one.
     *
     * @param list<AppExtension> $extensions
     */
    public static function planPolicy(array $extensions): ?PlanPolicy
    {
        $policy = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->planPolicy();
            if ($candidate === null) {
                continue;
            }
            if ($policy !== null) {
                throw new ConfigException('config: more than one extension provides a PlanPolicy — only one may.');
            }
            $policy = $candidate;
        }

        return $policy;
    }

    /**
     * Merged CSRF header exemptions of all extensions (path => header).
     *
     * @param list<AppExtension> $extensions
     * @return array<string, string>
     */
    public static function csrfExemptions(array $extensions): array
    {
        $merged = [];
        foreach ($extensions as $extension) {
            foreach ($extension->csrfExemptions() as $path => $header) {
                if (isset($merged[$path])) {
                    throw new ConfigException("config: two extensions declare a CSRF exemption for \"{$path}\".");
                }
                $merged[$path] = $header;
            }
        }

        return $merged;
    }

    /**
     * The single deletion precondition contributed by $extensions, or null.
     *
     * @param list<AppExtension> $extensions
     */
    public static function accountDeletionPrecondition(array $extensions, ExtensionContext $ctx): ?AccountDeletionPrecondition
    {
        $precondition = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->accountDeletionPrecondition($ctx);
            if ($candidate === null) {
                continue;
            }
            if ($precondition !== null) {
                throw new ConfigException('config: more than one extension provides an AccountDeletionPrecondition — only one may.');
            }
            $precondition = $candidate;
        }

        return $precondition;
    }

    /**
     * Bootstrap features of all extensions merged over $coreFeatures.
     *
     * @param list<AppExtension>   $extensions
     * @param array<string, mixed> $coreFeatures
     * @return array<string, mixed>
     */
    public static function bootstrapFeatures(array $extensions, array $coreFeatures): array
    {
        foreach ($extensions as $extension) {
            $coreFeatures = array_merge($coreFeatures, $extension->bootstrapFeatures());
        }

        return $coreFeatures;
    }

    /**
     * Merged static response headers of all extensions (name => value).
     * A header core sets itself (SecurityHeaderMiddleware::CORE_HEADERS) or
     * one declared by two extensions is a configuration error.
     *
     * @param list<AppExtension> $extensions
     * @return array<string, string>
     */
    public static function responseHeaders(array $extensions): array
    {
        $reserved = array_map(strtolower(...), SecurityHeaderMiddleware::CORE_HEADERS);
        $merged   = [];
        $seen     = [];
        foreach ($extensions as $extension) {
            foreach ($extension->responseHeaders() as $name => $value) {
                $key = strtolower($name);
                if (in_array($key, $reserved, true)) {
                    throw new ConfigException("config: extension declares response header \"{$name}\", which core sets itself and extensions may not override.");
                }
                if (isset($seen[$key])) {
                    throw new ConfigException("config: two extensions declare the response header \"{$name}\".");
                }
                $seen[$key]    = true;
                $merged[$name] = $value;
            }
        }

        return $merged;
    }

    /**
     * Route middleware of all extensions merged per CoreRoute name, in
     * extension order. An unknown route name is a configuration error.
     *
     * @param list<AppExtension> $extensions
     * @return array<string, list<MiddlewareInterface>>
     */
    public static function routeMiddleware(array $extensions, ExtensionContext $ctx): array
    {
        $known  = CoreRoute::all();
        $merged = [];
        foreach ($extensions as $extension) {
            foreach ($extension->routeMiddleware($ctx) as $routeName => $middlewares) {
                if (!in_array($routeName, $known, true)) {
                    throw new ConfigException("config: extension attaches middleware to unknown core route \"{$routeName}\" — see " . CoreRoute::class . ' for the routes that can be targeted.');
                }
                foreach ($middlewares as $middleware) {
                    $merged[$routeName][] = $middleware;
                }
            }
        }

        return $merged;
    }
}
