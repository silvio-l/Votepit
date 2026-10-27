<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * 0089_add_trusted_authors (broaden-use-cases issue 11): MySQL DDL,
 * content-checked (the SQLite test schema in IntegrationTestCase mirrors
 * it). Both columns are nullable without a default value, so every
 * existing standing row stays "not trusted" — no backfill, no behaviour
 * change.
 */
final class TrustedAuthorsMigrationTest extends TestCase
{
    public function test_migration_adds_nullable_trust_columns_to_the_standing_table(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/0089_add_trusted_authors.sql';
        self::assertSame('0089_add_trusted_authors', (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('ALTER TABLE account_member_standing', $sql);
        self::assertStringContainsString('ADD COLUMN trusted_at DATETIME NULL', $sql);
        self::assertStringContainsString('ADD COLUMN trusted_by_user_id BIGINT UNSIGNED NULL', $sql);
        self::assertStringContainsString('FOREIGN KEY (trusted_by_user_id) REFERENCES users(id) ON DELETE SET NULL', $sql);
        self::assertStringNotContainsString('UPDATE ', $sql, 'no backfill');
    }
}
