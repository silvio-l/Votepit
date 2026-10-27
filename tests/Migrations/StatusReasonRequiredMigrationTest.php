<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * 0115_add_boards_status_reason_required: MySQL-specific DDL (… AFTER …), so it
 * is only content-checked (same rationale as BoardPhaseWindowsMigrationTest);
 * the behaviour runs against the SQLite mirror in IntegrationTestCase. Pins the
 * "defaults unchanged" invariant: one NOT NULL TINYINT(1) flag, default 0, no
 * data migration — every existing board keeps the optional reason.
 */
final class StatusReasonRequiredMigrationTest extends TestCase
{
    private const VERSION = '0115_add_boards_status_reason_required';

    public function test_migration_adds_one_default_off_flag_without_backfill(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/' . self::VERSION . '.sql';
        self::assertSame(self::VERSION, (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString(
            'ALTER TABLE boards ADD COLUMN status_reason_required TINYINT(1) NOT NULL DEFAULT 0 AFTER vote_reasons_enabled',
            $sql,
        );
        self::assertStringNotContainsString('UPDATE ', $sql);
        self::assertStringNotContainsString('DROP ', $sql);
    }
}
