<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * Content comparison for 0011_add_account_confirmed_at (cloud signup
 * onboarding). Deliberately runs NO SQL — MySQL-specific DDL (ALTER ...
 * AFTER) doesn't run against the SQLite test connection, see
 * AccountSchemaMigrationTest for the same rationale.
 */
final class AccountConfirmedAtMigrationTest extends TestCase
{
    private const VERSION = '0011_add_account_confirmed_at';

    private function coreDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_migration_file_exists_with_expected_version(): void
    {
        $path = $this->coreDir() . '/migrations/' . self::VERSION . '.sql';
        self::assertFileExists($path);
        self::assertSame(self::VERSION, (new SqlFileMigration($path))->version());
    }

    public function test_adds_nullable_confirmed_at_and_backfills_existing_rows(): void
    {
        $sql = (string) file_get_contents($this->coreDir() . '/migrations/' . self::VERSION . '.sql');

        self::assertStringContainsString(
            'ALTER TABLE accounts ADD COLUMN confirmed_at DATETIME NULL AFTER deletion_scheduled_at',
            $sql,
        );
        self::assertStringContainsString(
            'UPDATE accounts SET confirmed_at = created_at WHERE confirmed_at IS NULL',
            $sql,
        );
    }
}
