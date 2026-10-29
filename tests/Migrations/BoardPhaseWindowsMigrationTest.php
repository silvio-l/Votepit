<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * 0082_add_board_phase_windows: MySQL-specific DDL (… AFTER …), so it is only
 * content-checked (same rationale as BoardStatusesMigrationTest); the
 * behaviour runs against the SQLite mirror in IntegrationTestCase. Pins the
 * "defaults unchanged" invariant: four NULLABLE columns, no default value,
 * no data migration — every existing board keeps having no deadline.
 */
final class BoardPhaseWindowsMigrationTest extends TestCase
{
    private const VERSION = '0082_add_board_phase_windows';

    public function test_migration_adds_four_nullable_datetime_columns_without_backfill(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/' . self::VERSION . '.sql';
        self::assertSame(self::VERSION, (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        foreach (['submissions_opens_at', 'submissions_closes_at', 'voting_opens_at', 'voting_closes_at'] as $column) {
            self::assertMatchesRegularExpression('/ALTER TABLE boards ADD COLUMN ' . $column . ' DATETIME NULL /', $sql);
        }
        self::assertStringNotContainsString('UPDATE ', $sql);
        self::assertStringNotContainsString('DEFAULT', $sql);
    }
}
