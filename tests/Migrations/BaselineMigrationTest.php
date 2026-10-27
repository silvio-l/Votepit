<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\MigrationRunner;
use Votepit\Migrations\SqlFileMigration;

/**
 * Content-only comparison for migrations/0000_baseline.sql. Deliberately runs
 * NO SQL (0000_baseline.sql is MySQL-specific DDL — ENGINE=InnoDB,
 * FULLTEXT, TINYINT(1) — and doesn't run against the SQLite test connection).
 * See MigrationRunnerTest for the generic runner mechanics.
 */
final class BaselineMigrationTest extends TestCase
{
    private function coreDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_baseline_file_exists_with_expected_version(): void
    {
        $path = $this->coreDir() . '/migrations/0000_baseline.sql';

        self::assertFileExists($path);
        self::assertSame('0000_baseline', (new SqlFileMigration($path))->version());
    }

    public function test_baseline_ends_with_the_unmodified_schema_sql_content(): void
    {
        $baseline = (string) file_get_contents($this->coreDir() . '/migrations/0000_baseline.sql');
        $schema   = (string) file_get_contents($this->coreDir() . '/db/schema.sql');

        self::assertNotSame('', $schema);
        self::assertStringEndsWith($schema, $baseline);
    }

    public function test_baseline_header_documents_the_never_edit_again_principle(): void
    {
        $baseline = (string) file_get_contents($this->coreDir() . '/migrations/0000_baseline.sql');

        self::assertStringContainsString('NIEMALS mehr', $baseline); // export-ok: comment-language (pins frozen migration text)
    }

    /**
     * discover() against the REAL migrations/ directory must not execute any
     * SQL file (that only happens in up()) — pure filesystem/name check.
     */
    public function test_real_migrations_dir_is_discovered_with_baseline_first(): void
    {
        $runner     = new MigrationRunner(self::createStub(\Doctrine\DBAL\Connection::class), $this->coreDir() . '/migrations');
        $migrations = $runner->discover();

        self::assertNotEmpty($migrations);
        self::assertSame('0000_baseline', $migrations[0]->version());
    }
}
