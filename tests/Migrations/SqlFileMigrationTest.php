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

    public function test_a_failing_statement_reports_its_position_and_how_many_statements_already_applied(): void
    {
        // review-2026-09-04-fixes item 5: the 3rd statement is invalid SQL —
        // the first two (both valid CREATE TABLEs) already landed on the
        // connection before the failure, exactly the partial-apply scenario
        // the error message needs to make legible to an operator.
        $path = $this->writeFixture(
            '0004_partial_failure.sql',
            "CREATE TABLE t_one (id INTEGER NOT NULL PRIMARY KEY);\n"
            . "CREATE TABLE t_two (id INTEGER NOT NULL PRIMARY KEY);\n"
            . "CREATE TABLE t_two (id INTEGER NOT NULL PRIMARY KEY);\n" // duplicate -> fails
            . "CREATE TABLE t_four (id INTEGER NOT NULL PRIMARY KEY);\n",
        );

        $conn = $this->conn();

        try {
            (new SqlFileMigration($path))->up($conn);
            self::fail('Expected a RuntimeException for the duplicate CREATE TABLE.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('statement 3 of 4', $e->getMessage());
            self::assertStringContainsString('2 statement(s) before it were already applied', $e->getMessage());
            self::assertStringContainsString('CREATE TABLE t_two', $e->getMessage());
        }

        // The two statements before the failure really did land on the
        // schema — t_four (after the failure) never got a chance to run.
        self::assertSame(['t_one', 't_two'], $this->tableNames($conn));
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
