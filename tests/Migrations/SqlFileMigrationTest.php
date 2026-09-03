<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * Pure unit level: version() derivation + statement split/execution,
 * isolated from MigrationRunner.
 */
final class SqlFileMigrationTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/votepit-sqlfilemigration-' . uniqid();
        mkdir($this->fixtureDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->fixtureDir . '/*');
        array_map(unlink(...), $files === false ? [] : $files);
        rmdir($this->fixtureDir);
        parent::tearDown();
    }

    private function conn(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function test_version_is_filename_without_extension(): void
    {
        $path = $this->writeFixture('0001_widgets.sql', 'CREATE TABLE widgets (id INTEGER);');

        self::assertSame('0001_widgets', (new SqlFileMigration($path))->version());
    }

    public function test_up_executes_each_statement_split_on_semicolon_newline(): void
    {
        $path = $this->writeFixture(
            '0002_two_tables.sql',
            "CREATE TABLE t_one (id INTEGER NOT NULL PRIMARY KEY);\n"
            . "CREATE TABLE t_two (id INTEGER NOT NULL PRIMARY KEY);\n",
        );

        $conn = $this->conn();
        (new SqlFileMigration($path))->up($conn);

        self::assertSame(['t_one', 't_two'], $this->tableNames($conn));
    }

    public function test_up_ignores_blank_lines_between_statements(): void
    {
        $path = $this->writeFixture(
            '0003_with_blank_lines.sql',
            "-- Comment\nCREATE TABLE t_a (id INTEGER NOT NULL PRIMARY KEY);\n\n\nCREATE TABLE t_b (id INTEGER NOT NULL PRIMARY KEY);\n",
        );

        $conn = $this->conn();
        (new SqlFileMigration($path))->up($conn);

        self::assertSame(['t_a', 't_b'], $this->tableNames($conn));
    }

    private function writeFixture(string $name, string $contents): string
    {
        $path = $this->fixtureDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return list<string> */
    private function tableNames(Connection $conn): array
    {
        /** @var list<string> $names */
        $names = $conn->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");

        return $names;
    }
}
