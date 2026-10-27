<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;
use Votepit\Security\ApiTokenScope;

/**
 * 0081_add_api_token_moderate_scope: MySQL-specific DDL (DROP CHECK), only
 * content-checked — same rationale as IdeaImportsMigrationTest. The SQLite
 * test schema has no CHECK on api_token_boards.scope.
 */
final class ApiTokenModerateScopeMigrationTest extends TestCase
{
    public function test_migration_widens_the_scope_check_to_every_known_scope(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/0081_add_api_token_moderate_scope.sql';
        self::assertSame('0081_add_api_token_moderate_scope', (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('DROP CHECK chk_api_token_boards_scope', $sql);
        self::assertStringContainsString(
            "CHECK (scope IN ('" . implode("', '", ApiTokenScope::ALL) . "'))",
            $sql,
        );
    }
}
