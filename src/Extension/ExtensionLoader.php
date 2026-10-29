<?php

declare(strict_types=1);

namespace Votepit\Extension;

use Doctrine\DBAL\Connection;
use Psr\Http\Server\MiddlewareInterface;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Cron\CronJobDefinition;
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
     * Cron jobs contributed by $extensions, in extension order. Name clashes
     * are checked by Votepit\Cron\CronJobRegistry::forConfig(), which also
     * sees core's jobs.
     *
     * @param list<AppExtension> $extensions
     * @return list<CronJobDefinition>
     */
    public static function cronJobs(array $extensions, string $coreRoot): array
    {
        $jobs = [];
        foreach ($extensions as $extension) {
            if ($extension instanceof CronJobProvider) {
                array_push($jobs, ...$extension->cronJobs($coreRoot));
            }
        }

        return $jobs;
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
     * The single terms-acceptance gate contributed by $extensions, or null.
     * Takes $conn directly (not ExtensionContext) — resolved by AppFactory
     * before the account-prefixed routes/ExtensionContext exist yet, see
     * AppExtension::termsAcceptanceGate() class doc.
     *
     * @param list<AppExtension> $extensions
     */
    public static function termsAcceptanceGate(array $extensions, Connection $conn): ?TermsAcceptanceGate
    {
        $gate = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->termsAcceptanceGate($conn);
            if ($candidate === null) {
                continue;
            }
            if ($gate !== null) {
                throw new ConfigException('config: more than one extension provides a TermsAcceptanceGate — only one may.');
            }
            $gate = $candidate;
        }

        return $gate;
    }

    /**
     * The single board-operator-acknowledgment gate contributed by
     * $extensions, or null. Takes ExtensionContext (not $conn) — resolved by
     * AppFactory well after the account-prefixed routes/ExtensionContext
     * exist, see AppExtension::boardOperatorAcknowledgmentGate() class doc.
     *
     * @param list<AppExtension> $extensions
     */
    public static function boardOperatorAcknowledgmentGate(array $extensions, ExtensionContext $ctx): ?BoardOperatorAcknowledgmentGate
    {
        $gate = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->boardOperatorAcknowledgmentGate($ctx);
            if ($candidate === null) {
                continue;
            }
            if ($gate !== null) {
                throw new ConfigException('config: more than one extension provides a BoardOperatorAcknowledgmentGate — only one may.');
            }
            $gate = $candidate;
        }

        return $gate;
    }

    /**
     * The single board-operator-compliance enforcer contributed by
     * $extensions, or null. Same ExtensionContext-timing reasoning as
     * boardOperatorAcknowledgmentGate() above.
     *
     * @param list<AppExtension> $extensions
     */
    public static function boardComplianceEnforcer(array $extensions, ExtensionContext $ctx): ?BoardComplianceEnforcer
    {
        $enforcer = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->boardComplianceEnforcer($ctx);
            if ($candidate === null) {
                continue;
            }
            if ($enforcer !== null) {
                throw new ConfigException('config: more than one extension provides a BoardComplianceEnforcer — only one may.');
            }
            $enforcer = $candidate;
        }

        return $enforcer;
    }

    /**
     * The single duplicate confirmer contributed by $extensions, or null
     * (deterministic suggestions only). Same ExtensionContext timing as
     * boardComplianceEnforcer() above.
     *
     * @param list<AppExtension> $extensions
     */
    public static function duplicateConfirmer(array $extensions, ExtensionContext $ctx): ?DuplicateConfirmer
    {
        $confirmer = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->duplicateConfirmer($ctx);
            if ($candidate === null) {
                continue;
            }
            if ($confirmer !== null) {
                throw new ConfigException('config: more than one extension provides a DuplicateConfirmer — only one may.');
            }
            $confirmer = $candidate;
        }

        return $confirmer;
    }

    /**
     * The single tag suggester contributed by $extensions, or null (no AI tag
     * suggestions). Same ExtensionContext timing as duplicateConfirmer().
     *
     * @param list<AppExtension> $extensions
     */
    public static function tagSuggester(array $extensions, ExtensionContext $ctx): ?TagSuggester
    {
        $suggester = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->tagSuggester($ctx);
            if ($candidate === null) {
                continue;
            }
            if ($suggester !== null) {
                throw new ConfigException('config: more than one extension provides a TagSuggester — only one may.');
            }
            $suggester = $candidate;
        }

        return $suggester;
    }

    /**
     * The upload-terms version of the extension that provides one (null = no consent required).
     * Two providers are a configuration error.
     *
     * @param list<AppExtension> $extensions
     */
    public static function uploadTermsVersion(array $extensions): ?string
    {
        $version = null;
        foreach ($extensions as $extension) {
            $candidate = $extension->uploadTermsVersion();
            if ($candidate === null) {
                continue;
            }
            if ($version !== null) {
                throw new ConfigException('config: more than one extension provides an upload-terms version — only one may.');
            }
            $version = $candidate;
        }

        return $version;
    }

    /**
     * Merged migration streams of all extensions (tracking table => dir).
     * Fail-fast on core's own table or a table declared twice — two streams
     * sharing a tracking table would make each other's versions look applied.
     *
     * @param list<AppExtension> $extensions
     * @return array<string, string>
     */
    public static function migrationStreams(array $extensions): array
    {
        $merged = [];
        foreach ($extensions as $extension) {
            foreach ($extension->migrationStreams() as $table => $dir) {
                if ($table === 'schema_migrations') {
                    throw new ConfigException('config: an extension declares core\'s own "schema_migrations" as its migration tracking table.');
                }
                if (isset($merged[$table])) {
                    throw new ConfigException("config: two extensions declare the migration tracking table \"{$table}\".");
                }
                $merged[$table] = $dir;
            }
        }

        return $merged;
    }

    /**
     * Lets every extension re-point its own user references to the
     * "deleted user" placeholder before a user row is erased (see
     * AppExtension::reassignUserReferences()). Called inside the erasure
     * transaction; an exception propagates and rolls it back.
     *
     * @param list<AppExtension> $extensions
     */
    public static function reassignUserReferences(array $extensions, Connection $conn, int $userId, int $placeholderUserId): void
    {
        foreach ($extensions as $extension) {
            $extension->reassignUserReferences($conn, $userId, $placeholderUserId);
        }
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
