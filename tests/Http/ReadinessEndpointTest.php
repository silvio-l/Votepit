<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Migrations\MigrationRunner;
use Votepit\Persistence\CronHeartbeatRepository;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\StubExtension;

/**
 * GET /ready — readiness probe (competitor-parity issue 06): 200 only when
 * the DB answers, no core/extension migration is pending and the cron
 * manager's heartbeat is fresh; otherwise 503. The body is minimal in both
 * cases and never names a failing check, a version, a path or an error.
 */
final class ReadinessEndpointTest extends IntegrationTestCase
{
    private const CORE_MIGRATIONS = __DIR__ . '/../../migrations';

    private string $extensionMigrationsDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The test schema is hand-written (no schema_migrations) — mark every
        // shipped core migration applied, as on a fully migrated installation.
        $this->markApplied(self::CORE_MIGRATIONS, 'schema_migrations');
        (new CronHeartbeatRepository($this->conn))->recordSuccess('cron-manager');
    }

    protected function tearDown(): void
    {
        if ($this->extensionMigrationsDir !== '' && is_dir($this->extensionMigrationsDir)) {
            $files = glob($this->extensionMigrationsDir . '/*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($this->extensionMigrationsDir);
        }

        parent::tearDown();
    }

    public function test_ready_installation_answers_200_with_minimal_body(): void
    {
        $response = $this->ready();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function test_pending_core_migration_answers_503(): void
    {
        $this->conn->executeStatement('DELETE FROM schema_migrations WHERE version = (SELECT MAX(version) FROM schema_migrations)');

        $this->assertNotReady($this->ready(), 'migrations');
    }

    public function test_missing_tracking_table_answers_503_without_creating_it(): void
    {
        $this->conn->executeStatement('DROP TABLE schema_migrations');

        $this->assertNotReady($this->ready(), 'migrations');

        // A probe must never write: the runner's CREATE TABLE IF NOT EXISTS stays unused.
        self::assertFalse($this->conn->createSchemaManager()->tablesExist(['schema_migrations']));
    }

    public function test_pending_extension_migration_answers_503_and_applied_answers_200(): void
    {
        $this->extensionMigrationsDir = sys_get_temp_dir() . '/votepit-ext-migrations-' . uniqid();
        mkdir($this->extensionMigrationsDir);
        file_put_contents($this->extensionMigrationsDir . '/0001_ext_table.sql', 'CREATE TABLE ext_table (id INTEGER);');

        $extension = new StubExtension(['migration_streams' => ['ext_schema_migrations' => $this->extensionMigrationsDir]]);

        // Extension stream never migrated (no tracking table yet) → not ready.
        $this->assertNotReady($this->ready(extensions: [$extension]), 'migrations');

        $this->markApplied($this->extensionMigrationsDir, 'ext_schema_migrations');
        self::assertSame(200, $this->ready(extensions: [$extension])->getStatusCode());
    }

    public function test_missing_cron_manager_heartbeat_answers_503(): void
    {
        $this->conn->executeStatement("DELETE FROM cron_heartbeats WHERE job_name = 'cron-manager'");

        $this->assertNotReady($this->ready(), 'cron');
    }

    public function test_stale_cron_manager_heartbeat_answers_503_with_default_threshold(): void
    {
        $this->ageHeartbeat(6);

        $this->assertNotReady($this->ready(), 'cron');
    }

    public function test_a_tick_four_minutes_ago_is_still_ready_with_default_threshold(): void
    {
        $this->ageHeartbeat(4);

        self::assertSame(200, $this->ready()->getStatusCode());
    }

    public function test_a_fresh_tick_whose_job_failed_still_answers_ready(): void
    {
        // Readiness is about the manager ticking, not about job outcomes; the
        // failure is the alert mail's and the watchdog's concern.
        (new CronHeartbeatRepository($this->conn))->recordFailure('cron-manager', 'registry broken');

        self::assertSame(200, $this->ready()->getStatusCode());
    }

    public function test_cron_threshold_is_configurable(): void
    {
        $this->ageHeartbeat(121);
        $config = Config::fromArray($this->testConfigArray(['readiness_cron_max_age_minutes' => 180]));

        self::assertSame(200, $this->ready(config: $config)->getStatusCode());

        $this->ageHeartbeat(181);
        self::assertSame(503, $this->ready(config: $config)->getStatusCode());
    }

    public function test_other_jobs_heartbeats_do_not_count(): void
    {
        $this->conn->executeStatement("DELETE FROM cron_heartbeats WHERE job_name = 'cron-manager'");
        (new CronHeartbeatRepository($this->conn))->recordSuccess('cleanup-rate-limits');

        self::assertSame(503, $this->ready()->getStatusCode());
    }

    public function test_health_stays_liveness_only_while_not_ready(): void
    {
        $this->conn->executeStatement('DROP TABLE schema_migrations');

        $response = $this->createApp()->handle((new ServerRequestFactory())->createServerRequest('GET', '/health'));

        self::assertSame(200, $response->getStatusCode());
    }

    /** @param list<\Votepit\Extension\AppExtension>|null $extensions */
    private function ready(?array $extensions = null, ?Config $config = null): ResponseInterface
    {
        return $this->createApp(extensions: $extensions, config: $config)
            ->handle((new ServerRequestFactory())->createServerRequest('GET', '/ready'));
    }

    private function assertNotReady(ResponseInterface $response, string $failedCheck): void
    {
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        // No internals in the public body — not even which check failed.
        self::assertSame('{"ok":false}', (string) $response->getBody());
        // The operator finds the reason in the audit log instead.
        self::assertStringContainsString('readiness.not_ready', $this->readAuditLog());
        self::assertStringContainsString($failedCheck, $this->readAuditLog());
    }

    private function markApplied(string $dir, string $trackingTable): void
    {
        $runner = new MigrationRunner($this->conn, $dir, null, $trackingTable);
        $runner->ensureTrackingTable();
        foreach ($runner->discover() as $migration) {
            $this->conn->insert($trackingTable, ['version' => $migration->version(), 'checksum' => str_repeat('0', 64)]);
        }
    }

    private function ageHeartbeat(int $minutes): void
    {
        $this->conn->executeStatement(
            "UPDATE cron_heartbeats SET last_run_at = :at WHERE job_name = 'cron-manager'",
            ['at' => (new \DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s')],
        );
    }
}
