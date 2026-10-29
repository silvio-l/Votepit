<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * 0077_add_idea_imports: MySQL-specific DDL (… AFTER …, ENGINE), only
 * content-checked — same rationale as IdeaCountersMigrationTest. The SQLite
 * test schema (IntegrationTestCase) mirrors it and the import tests run
 * against that.
 */
final class IdeaImportsMigrationTest extends TestCase
{
    public function test_migration_adds_the_import_table_and_idea_columns(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/0077_add_idea_imports.sql';
        self::assertSame('0077_add_idea_imports', (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('CREATE TABLE idea_imports', $sql);
        self::assertStringContainsString('UNIQUE KEY uq_idea_imports_board_key (board_id, idempotency_key)', $sql);
        self::assertStringContainsString('ADD COLUMN imported_vote_count INT NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('ADD COLUMN import_id BIGINT UNSIGNED NULL', $sql);
        self::assertStringContainsString('REFERENCES idea_imports(id) ON DELETE SET NULL', $sql);
    }
}
