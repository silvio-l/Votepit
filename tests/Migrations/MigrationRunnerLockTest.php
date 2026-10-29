<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;
use Votepit\Migrations\MigrationLockException;
use Votepit\Migrations\MigrationRunner;

/**
 * MySQL advisory lock around MigrationRunner::migrate() — two concurrent
 * runs (e.g. a deploy script and a manual `bin/migrate.php`) must never
 * apply the same migration twice.
 *
 * The test suite has no MySQL server, so the connection is a mock that
 * reports a MySQL platform and answers GET_LOCK/RELEASE_LOCK like a server
 * would: GET_LOCK returns 1 (acquired), 0 (timeout — another session holds
 * it) or NULL (error). The SQLite path (no lock) is covered by
 * MigrationRunnerTest.
 */
final class MigrationRunnerLockTest extends TestCase
{
    private string $fixtureDir;

    /** @var list<string> Every SQL string the runner sent, in order. */
    private array $log = [];

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/votepit-migrations-lock-' . uniqid();
        mkdir($this->fixtureDir);
        file_put_contents($this->fixtureDir . '/0001_a.sql', 'CREATE TABLE lock_a (id INT NOT NULL PRIMARY KEY);');
    }

    protected function tearDown(): void
    {
        $files = glob($this->fixtureDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->fixtureDir);
    }

    public function test_second_run_refuses_while_lock_is_held_and_applies_nothing(): void
    {
        $runner = new MigrationRunner($this->mysqlConnection(lockResult: 0), $this->fixtureDir);

        try {
            $runner->migrate();
            self::fail('Expected MigrationLockException');
        } catch (MigrationLockException $e) {
            self::assertStringContainsString('Another migration run', $e->getMessage());
        }

        self::assertSame([], $this->matching('CREATE TABLE lock_a'), 'no migration may run without the lock');
        self::assertSame([], $this->matching('INSERT'), 'nothing may be tracked without the lock');
        self::assertSame([], $this->matching('RELEASE_LOCK'), 'a lock never acquired must not be released');
    }

    public function test_lock_error_is_treated_as_not_acquired(): void
    {
        $runner = new MigrationRunner($this->mysqlConnection(lockResult: null), $this->fixtureDir);

        $this->expectException(MigrationLockException::class);
        $runner->migrate();
    }

    public function test_lock_is_acquired_before_and_released_after_applying(): void
    {
        $runner  = new MigrationRunner($this->mysqlConnection(lockResult: 1), $this->fixtureDir);
        $applied = $runner->migrate();

        self::assertSame(['0001_a'], $applied);

        $get     = $this->firstIndex('GET_LOCK');
        $create  = $this->firstIndex('CREATE TABLE lock_a');
        $release = $this->firstIndex('RELEASE_LOCK');
        self::assertTrue($get < $create && $create < $release, implode("\n", $this->log));
    }

    public function test_lock_is_released_when_a_migration_fails(): void
    {
        $runner = new MigrationRunner($this->mysqlConnection(lockResult: 1, failOn: 'CREATE TABLE lock_a'), $this->fixtureDir);

        try {
            $runner->migrate();
            self::fail('Expected the failing migration to throw');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(MigrationLockException::class, $e);
        }

        self::assertCount(1, $this->matching('RELEASE_LOCK'));
    }

    private function mysqlConnection(?int $lockResult, ?string $failOn = null): Connection
    {
        $conn = self::createStub(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $conn->method('fetchOne')->willReturnCallback(function (string $sql) use ($lockResult): mixed {
            $this->log[] = $sql;
            if (str_contains($sql, 'GET_LOCK')) {
                return $lockResult;
            }

            return 1;
        });
        $conn->method('executeStatement')->willReturnCallback(function (string $sql) use ($failOn): int {
            $this->log[] = $sql;
            if ($failOn !== null && str_contains($sql, $failOn)) {
                throw new \RuntimeException('boom');
            }

            return 0;
        });
        $conn->method('fetchFirstColumn')->willReturnCallback(function (string $sql): array {
            $this->log[] = $sql;

            return [];
        });
        $conn->method('insert')->willReturnCallback(function (string $table): int {
            $this->log[] = "INSERT INTO {$table}";

            return 1;
        });

        return $conn;
    }

    /** @return list<string> */
    private function matching(string $needle): array
    {
        return array_values(array_filter($this->log, static fn (string $sql): bool => str_contains($sql, $needle)));
    }

    private function firstIndex(string $needle): int
    {
        foreach ($this->log as $i => $sql) {
            if (str_contains($sql, $needle)) {
                return $i;
            }
        }
        self::fail("No SQL containing '{$needle}' was sent:\n" . implode("\n", $this->log));
    }
}
