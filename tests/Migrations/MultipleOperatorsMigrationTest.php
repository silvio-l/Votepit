<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * Content check for 0071_allow_multiple_operators, which lifts the
 * single-operator constraint 0040 introduced. Runs no SQL — same rationale as
 * EmailHmacDdlMigrationTest (MySQL-specific DDL, SQLite test connection).
 */
final class MultipleOperatorsMigrationTest extends TestCase
{
    private function sql(string $version): string
    {
        $path = dirname(__DIR__, 2) . "/migrations/{$version}.sql";
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The dropped index/column names must match what 0040 actually created,
     * otherwise the migration fails on a real installation.
     */
    public function test_0071_drops_exactly_the_index_and_column_0040_created(): void
    {
        $sql0040 = $this->sql('0040_enforce_single_operator');
        self::assertSame(1, preg_match('/ADD UNIQUE KEY (\w+) \((\w+)\)/', $sql0040, $m));
        [, $indexName, $columnName] = $m;

        $sql0071 = $this->sql('0071_allow_multiple_operators');
        self::assertStringContainsString("ALTER TABLE users DROP INDEX {$indexName}", $sql0071);
        self::assertStringContainsString("ALTER TABLE users DROP COLUMN {$columnName}", $sql0071);
        self::assertLessThan(
            strpos($sql0071, "DROP COLUMN {$columnName}"),
            strpos($sql0071, "DROP INDEX {$indexName}"),
            'Drop the unique index before the generated column it covers.',
        );
    }
}
