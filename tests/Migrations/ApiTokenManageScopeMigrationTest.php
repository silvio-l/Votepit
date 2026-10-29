<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;
use Votepit\Security\ApiTokenScope;

/**
 * 0117_add_api_token_manage_scope: MySQL/MariaDB DDL (DROP CONSTRAINT), only
 * content-checked, same as 0081 (ApiTokenModerateScopeMigrationTest).
 */
final class ApiTokenManageScopeMigrationTest extends TestCase
{
    public function test_migration_widens_the_scope_check_to_every_known_scope(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/0117_add_api_token_manage_scope.sql';
        self::assertSame('0117_add_api_token_manage_scope', (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('DROP CONSTRAINT chk_api_token_boards_scope', $sql);
        self::assertStringContainsString(
            "CHECK (scope IN ('" . implode("', '", ApiTokenScope::ALL) . "'))",
            $sql,
        );
    }
}
