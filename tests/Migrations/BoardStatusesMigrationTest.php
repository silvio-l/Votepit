<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * 0073_create_board_statuses: MySQL-specific DDL (ENGINE/… AFTER …), so it
 * is only content-checked (same rationale as IdeaCountersMigrationTest);
 * the behaviour runs against the SQLite mirror in IntegrationTestCase.
 * Pins the invariants the code relies on: keys fit ideas.status
 * VARCHAR(16), labels are bounded, one key per board, rows die with their
 * board, and existing boards start un-customised (no data migration).
 */
final class BoardStatusesMigrationTest extends TestCase
{
    private const VERSION = '0073_create_board_statuses';

    private function sql(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::VERSION . '.sql');
    }

    public function test_migration_creates_board_statuses_and_the_board_flag(): void
    {
        self::assertSame(self::VERSION, (new SqlFileMigration(dirname(__DIR__, 2) . '/migrations/' . self::VERSION . '.sql'))->version());

        $sql = $this->sql();
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS board_statuses', $sql);
        self::assertMatchesRegularExpression('/status_key\s+VARCHAR\(16\) NOT NULL/', $sql);
        self::assertMatchesRegularExpression('/category\s+VARCHAR\(16\) NOT NULL/', $sql);
        self::assertMatchesRegularExpression('/label_de\s+VARCHAR\(40\) NULL/', $sql);
        self::assertMatchesRegularExpression('/label_en\s+VARCHAR\(40\) NULL/', $sql);
        self::assertStringContainsString('UNIQUE KEY uq_board_statuses_board_key (board_id, status_key)', $sql);
        self::assertStringContainsString('FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE', $sql);
        self::assertStringContainsString('ALTER TABLE boards ADD COLUMN statuses_customized TINYINT(1) NOT NULL DEFAULT 0', $sql);
    }

    public function test_migration_does_not_touch_existing_ideas(): void
    {
        self::assertStringNotContainsString('UPDATE ideas', $this->sql());
        self::assertStringNotContainsString('ALTER TABLE ideas', $this->sql());
    }
}
