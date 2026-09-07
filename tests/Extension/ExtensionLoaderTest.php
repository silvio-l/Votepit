<?php

declare(strict_types=1);

namespace Votepit\Tests\Extension;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Extension\DeletionBlocked;
use Votepit\Extension\ExtensionContext;
use Votepit\Extension\ExtensionLoader;
use Votepit\Http\CoreRoute;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\StubExtension;

final class ExtensionLoaderTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $extensions */
    private function config(array $extensions): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => str_repeat('b', 64),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'extensions'          => $extensions,
        ]);
    }

    private function context(): ExtensionContext
    {
        $conn            = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $responseFactory = new ResponseFactory();
        $config          = $this->config([]);
        $accountMembers  = new AccountMemberRepository($conn);

        return new ExtensionContext(
            new App($responseFactory),
            $config,
            $conn,
            $responseFactory,
            new AuditLogger(sys_get_temp_dir() . '/votepit-ext-loader-' . uniqid() . '.log'),
            new UnrestrictedPlanPolicy(),
            '',
            new AccountRepository($conn),
            $accountMembers,
            new BoardRepository($conn),
            new ApiTokenRepository($conn),
            new UserRepository($conn),
            new LoginSessionIssuer(new SessionService($config->appKey, $config->sessionLifetime, false), $accountMembers, $config),
        );
    }

    public function test_response_headers_merge_and_reserved_or_duplicate_names_are_rejected(): void
    {
        $merged = ExtensionLoader::responseHeaders([
            new StubExtension(['response_headers' => ['X-Robots-Tag' => 'noindex']]),
            new StubExtension(['response_headers' => ['X-Edition' => 'test']]),
        ]);
        self::assertSame(['X-Robots-Tag' => 'noindex', 'X-Edition' => 'test'], $merged);
        self::assertSame([], ExtensionLoader::responseHeaders([new StubExtension([])]));

        try {
            ExtensionLoader::responseHeaders([new StubExtension(['response_headers' => ['content-security-policy' => "default-src *"]])]);
            self::fail('core-owned header must be rejected');
        } catch (ConfigException $e) {
            self::assertStringContainsString('core sets itself', $e->getMessage());
        }

        $this->expectException(ConfigException::class);
        ExtensionLoader::responseHeaders([
            new StubExtension(['response_headers' => ['X-Robots-Tag' => 'noindex']]),
            new StubExtension(['response_headers' => ['x-robots-tag' => 'none']]),
        ]);
    }

    public function test_route_middleware_merges_per_route_and_unknown_names_are_rejected(): void
    {
        $ctx  = $this->context();
        $a    = StubExtension::reply(403, '{"a":true}');
        $b    = StubExtension::reply(403, '{"b":true}');
        $c    = StubExtension::reply(200, "User-agent: *\nDisallow: /\n", 'text/plain');

        $merged = ExtensionLoader::routeMiddleware([
            new StubExtension(['route_middleware' => [CoreRoute::INVITE_SEND => [$a], CoreRoute::ROBOTS_TXT => [$c]]]),
            new StubExtension(['route_middleware' => [CoreRoute::INVITE_SEND => [$b]]]),
        ], $ctx);
        self::assertSame([CoreRoute::INVITE_SEND => [$a, $b], CoreRoute::ROBOTS_TXT => [$c]], $merged);
        self::assertSame([], ExtensionLoader::routeMiddleware([new StubExtension([])], $ctx));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown core route');
        ExtensionLoader::routeMiddleware([new StubExtension(['route_middleware' => ['core.admin.members' => [$a]]])], $ctx);
    }

    public function test_no_extensions_by_default(): void
    {
        self::assertSame([], ExtensionLoader::fromConfig($this->config([])));
    }

    public function test_instantiates_declared_extension_with_its_options(): void
    {
        $extensions = ExtensionLoader::fromConfig($this->config([
            StubExtension::class => ['features' => ['billing' => true]],
        ]));

        self::assertCount(1, $extensions);
        self::assertInstanceOf(StubExtension::class, $extensions[0]);
        self::assertSame(['billing' => true], $extensions[0]->bootstrapFeatures());
    }

    public function test_unknown_class_fails_fast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not found');

        ExtensionLoader::fromConfig($this->config(['Votepit\\Nope\\Missing' => []]));
    }

    public function test_class_not_implementing_the_interface_fails_fast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('does not implement');

        ExtensionLoader::fromConfig($this->config([\stdClass::class => []]));
    }

    public function test_plan_policy_comes_from_the_single_contributing_extension(): void
    {
        $policy = new UnrestrictedPlanPolicy();
        $extensions = [
            new StubExtension([]),
            new StubExtension(['plan_policy' => $policy]),
        ];

        self::assertSame($policy, ExtensionLoader::planPolicy($extensions));
        self::assertNull(ExtensionLoader::planPolicy([new StubExtension([])]));
    }

    public function test_two_plan_policies_are_rejected(): void
    {
        $this->expectException(ConfigException::class);

        ExtensionLoader::planPolicy([
            new StubExtension(['plan_policy' => new UnrestrictedPlanPolicy()]),
            new StubExtension(['plan_policy' => new UnrestrictedPlanPolicy()]),
        ]);
    }

    public function test_csrf_exemptions_are_merged_and_conflicts_rejected(): void
    {
        $merged = ExtensionLoader::csrfExemptions([
            new StubExtension(['csrf_exemptions' => ['/a/hook' => 'X-A']]),
            new StubExtension(['csrf_exemptions' => ['/b/hook' => 'X-B']]),
        ]);
        self::assertSame(['/a/hook' => 'X-A', '/b/hook' => 'X-B'], $merged);

        $this->expectException(ConfigException::class);
        ExtensionLoader::csrfExemptions([
            new StubExtension(['csrf_exemptions' => ['/a/hook' => 'X-A']]),
            new StubExtension(['csrf_exemptions' => ['/a/hook' => 'X-B']]),
        ]);
    }

    public function test_deletion_precondition_single_or_rejected(): void
    {
        $blocked = new DeletionBlocked(422, 'nope', 'no');
        $ctx     = $this->context();
        self::assertNull(ExtensionLoader::accountDeletionPrecondition([new StubExtension([])], $ctx));
        self::assertNotNull(ExtensionLoader::accountDeletionPrecondition([new StubExtension(['block_deletion' => $blocked])], $ctx));

        $this->expectException(ConfigException::class);
        ExtensionLoader::accountDeletionPrecondition([
            new StubExtension(['block_deletion' => $blocked]),
            new StubExtension(['block_deletion' => $blocked]),
        ], $ctx);
    }

    public function test_bootstrap_features_merge_over_core_defaults(): void
    {
        $features = ExtensionLoader::bootstrapFeatures(
            [new StubExtension(['features' => ['billing' => true, 'legal_links' => ['de' => []]]])],
            ['board_smtp' => true, 'legal_links' => null],
        );

        self::assertSame(['board_smtp' => true, 'legal_links' => ['de' => []], 'billing' => true], $features);
    }
}
