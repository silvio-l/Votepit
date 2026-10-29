<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * 0081_add_api_token_moderate_scope: MySQL/MariaDB DDL (DROP CONSTRAINT), only
 * content-checked — same rationale as IdeaImportsMigrationTest. The SQLite
 * test schema has no CHECK on api_token_boards.scope. The migration is
 * historical: it widens the check to the scopes that existed then. Migration
 * 0117 adds 'manage' (see ApiTokenManageScopeMigrationTest).
 */
final class ApiTokenModerateScopeMigrationTest extends TestCase
{
    public function test_migration_widens_the_scope_check_to_every_known_scope(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/0081_add_api_token_moderate_scope.sql';
        self::assertSame('0081_add_api_token_moderate_scope', (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('DROP CONSTRAINT chk_api_token_boards_scope', $sql);
        self::assertStringContainsString("CHECK (scope IN ('read', 'write', 'moderate'))", $sql);
    }
}
