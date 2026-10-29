<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * Content check for 0074_add_user_self_service_deletion (MySQL-specific
 * DDL, not executable on the SQLite test connection — same rationale as
 * MultipleOperatorsMigrationTest). The SQLite test schema mirrors these
 * columns in IntegrationTestCase.
 */
final class UserSelfServiceDeletionMigrationTest extends TestCase
{
    private const VERSION = '0074_add_user_self_service_deletion';

    public function test_adds_the_grace_period_and_placeholder_columns(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/' . self::VERSION . '.sql';
        self::assertSame(self::VERSION, (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('ALTER TABLE users ADD COLUMN is_deleted_placeholder TINYINT(1) NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('ALTER TABLE users ADD COLUMN deletion_scheduled_at DATETIME NULL', $sql);
        self::assertStringContainsString('ALTER TABLE users ADD KEY idx_users_deletion_scheduled_at (deletion_scheduled_at)', $sql);
    }
}
