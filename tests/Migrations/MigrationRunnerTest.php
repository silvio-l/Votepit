<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use Votepit\Migrations\MigrationRunner;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * MigrationRunner mechanics (discover/pending/migrate) against fixture
 * migrations in a temp directory — deliberately NOT against the real
 * migrations/0000_baseline.sql (MySQL-specific DDL, see
 * BaselineMigrationTest for the content-only comparison). Runs on the
 * SQLite test connection from IntegrationTestCase, like the rest of the
 * persistence tests.
 */
final class MigrationRunnerTest extends IntegrationTestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/votepit-migrations-' . uniqid();
        mkdir($this->fixtureDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureDir);
        parent::tearDown();
    }

    public function test_pending_lists_all_discovered_migrations_on_fresh_db(): void
    {
        $this->writeFixture('0001_a.sql', 'CREATE TABLE mrt_a (id INTEGER NOT NULL PRIMARY KEY);');
        $this->writeFixture('0002_b.sql', 'CREATE TABLE mrt_b (id INTEGER NOT NULL PRIMARY KEY);');

        $runner = new MigrationRunner($this->conn, $this->fixtureDir);

        self::assertSame(['0001_a', '0002_b'], $this->versions($runner->pending()));
    }

    public function test_migrate_applies_tracks_and_leaves_nothing_pending(): void
    {
        $this->writeFixture('0001_a.sql', 'CREATE TABLE mrt_a (id INTEGER NOT NULL PRIMARY KEY);');
        $this->writeFixture('0002_b.sql', 'CREATE TABLE mrt_b (id INTEGER NOT NULL PRIMARY KEY);');

        $runner  = new MigrationRunner($this->conn, $this->fixtureDir);
        $applied = $runner->migrate();

        self::assertSame(['0001_a', '0002_b'], $applied);
        self::assertSame(1, $this->conn->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mrt_a'",
        ));
        self::assertSame(1, $this->conn->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mrt_b'",
        ));

        $rows = $this->conn->fetchAllAssociative('SELECT version, checksum FROM schema_migrations ORDER BY version');
        self::assertSame(['0001_a', '0002_b'], array_column($rows, 'version'));
        self::assertSame(hash('sha256', (string) file_get_contents($this->fixtureDir . '/0001_a.sql')), $rows[0]['checksum']);
        self::assertSame(hash('sha256', (string) file_get_contents($this->fixtureDir . '/0002_b.sql')), $rows[1]['checksum']);

        self::assertSame([], $runner->pending());
    }

    public function test_separate_tracking_table_keeps_version_streams_apart(): void
    {
        $this->writeFixture('0001_a.sql', 'CREATE TABLE mrt_a (id INTEGER NOT NULL PRIMARY KEY);');
        $core = new MigrationRunner($this->conn, $this->fixtureDir);
        $core->migrate();

        $extDir = sys_get_temp_dir() . '/votepit-migrations-ext-' . uniqid();
        mkdir($extDir);
        file_put_contents($extDir . '/0001_a.sql', 'CREATE TABLE ext_a (id INTEGER NOT NULL PRIMARY KEY);');
        $ext = new MigrationRunner($this->conn, $extDir, null, 'ext_schema_migrations');

        // Same version string as core's — a shared table would wrongly treat it as applied.
        self::assertSame(['0001_a'], $this->versions($ext->pending()));
        self::assertSame(['0001_a'], $ext->migrate());
        self::assertSame(1, $this->conn->fetchOne("SELECT COUNT(*) FROM ext_schema_migrations WHERE version = '0001_a'"));
        self::assertSame(1, $this->conn->fetchOne("SELECT COUNT(*) FROM schema_migrations WHERE version = '0001_a'"));
        self::assertSame([], $core->pending());

        $this->removeDir($extDir);
    }

    public function test_tracking_table_name_is_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MigrationRunner($this->conn, $this->fixtureDir, null, 'schema; DROP TABLE users');
    }

    public function test_migrate_second_call_is_a_no_op(): void
    {
        $this->writeFixture('0001_a.sql', 'CREATE TABLE mrt_a (id INTEGER NOT NULL PRIMARY KEY);');

        $runner = new MigrationRunner($this->conn, $this->fixtureDir);
        $runner->migrate();

        self::assertSame([], $runner->migrate());
        self::assertSame(1, $this->conn->fetchOne(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = '0001_a'",
        ));
    }

    public function test_failing_migration_is_not_tracked_and_stops_later_ones(): void
    {
        $this->writeFixture('0001_ok.sql', 'CREATE TABLE mrt_ok (id INTEGER NOT NULL PRIMARY KEY);');
        // Invalid SQL — SQLite rejects it.
        $this->writeFixture('0002_boom.sql', 'THIS IS NOT VALID SQL AT ALL;');
        $this->writeFixture('0003_never.sql', 'CREATE TABLE mrt_never (id INTEGER NOT NULL PRIMARY KEY);');

        $runner = new MigrationRunner($this->conn, $this->fixtureDir);

        try {
            $runner->migrate();
            self::fail('migrate() should have thrown because of 0002_boom.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('0002_boom', $e->getMessage());
        }

        // 0001 succeeded + tracked.
        self::assertSame(1, $this->conn->fetchOne(
            "SELECT COUNT(*) FROM schema_migrations WHERE version = '0001_ok'",
        ));
        // 0002 (failed) and 0003 (never attempted) are NOT tracked.
        self::assertSame(0, $this->conn->fetchOne(
            "SELECT COUNT(*) FROM schema_migrations WHERE version IN ('0002_boom', '0003_never')",
        ));
        // 0003_never was never reached (fail-fast).
        self::assertSame(0, $this->conn->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mrt_never'",
        ));

        self::assertSame(['0002_boom', '0003_never'], $this->versions((new MigrationRunner($this->conn, $this->fixtureDir))->pending()));
    }

    public function test_config_aware_migration_uses_upWithConfig_when_config_is_provided(): void
    {
        $this->writeFixture('0001_config_provided.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Doctrine\DBAL\Connection;
            use Votepit\Config;
            use Votepit\Migrations\ConfigAwareMigration;

            final class MigrationRunnerTestConfigProvidedMigration implements ConfigAwareMigration
            {
                public function version(): string
                {
                    return '0001_config_provided';
                }

                public function up(Connection $conn): void
                {
                    throw new \RuntimeException('up() must not be called when a Config is present.');
                }

                public function upWithConfig(Connection $conn, Config $config): void
                {
                    $conn->executeStatement('CREATE TABLE mrt_config_marker (env VARCHAR(16) NOT NULL)');
                    $conn->insert('mrt_config_marker', ['env' => $config->env]);
                }
            }

            return new MigrationRunnerTestConfigProvidedMigration();
            PHP);

        $runner = new MigrationRunner($this->conn, $this->fixtureDir, $this->testConfig());
        $runner->migrate();

        self::assertSame('dev', $this->conn->fetchOne('SELECT env FROM mrt_config_marker'));
    }

    public function test_config_aware_migration_falls_back_to_up_when_no_config_is_provided(): void
    {
        $this->writeFixture('0001_config_absent.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Doctrine\DBAL\Connection;
            use Votepit\Config;
            use Votepit\Migrations\ConfigAwareMigration;

            final class MigrationRunnerTestConfigAbsentMigration implements ConfigAwareMigration
            {
                public function version(): string
                {
                    return '0001_config_absent';
                }

                public function up(Connection $conn): void
                {
                    $conn->executeStatement('CREATE TABLE mrt_no_config_marker (id INTEGER NOT NULL PRIMARY KEY)');
                }

                public function upWithConfig(Connection $conn, Config $config): void
                {
                    throw new \RuntimeException('upWithConfig() must not be called without a Config.');
                }
            }

            return new MigrationRunnerTestConfigAbsentMigration();
            PHP);

        // No config passed -> runner must fall back to up().
        $runner = new MigrationRunner($this->conn, $this->fixtureDir);
        $runner->migrate();

        self::assertSame(1, $this->conn->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mrt_no_config_marker'",
        ));
    }

    private function writeFixture(string $name, string $contents): void
    {
        file_put_contents($this->fixtureDir . '/' . $name, $contents);
    }

    /**
     * @param list<\Votepit\Migrations\Migration> $migrations
     * @return list<string>
     */
    private function versions(array $migrations): array
    {
        return array_map(static fn (\Votepit\Migrations\Migration $m): string => $m->version(), $migrations);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
