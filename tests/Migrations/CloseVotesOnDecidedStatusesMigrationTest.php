<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use Votepit\Migrations\SqlFileMigration;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * 0114_close_votes_on_decided_system_statuses: the UPDATE is portable SQL, so
 * the behaviour runs against the SQLite mirror via SqlFileMigration::up().
 * Pins that only the system rows of done/declined/archived are closed and
 * that custom statuses keep the flag their admin chose.
 */
final class CloseVotesOnDecidedStatusesMigrationTest extends IntegrationTestCase
{
    private const VERSION = '0114_close_votes_on_decided_system_statuses';

    private function path(): string
    {
        return dirname(__DIR__, 2) . '/migrations/' . self::VERSION . '.sql';
    }

    public function test_migration_file_has_the_expected_version_and_a_single_scoped_update(): void
    {
        self::assertSame(self::VERSION, (new SqlFileMigration($this->path()))->version());

        $sql = (string) file_get_contents($this->path());
        self::assertStringContainsString(
            "UPDATE board_statuses SET allow_votes = 0 WHERE is_system = 1 AND status_key IN ('done', 'declined', 'archived')",
            $sql,
        );
        self::assertStringNotContainsString('ALTER TABLE', $sql);
        self::assertStringNotContainsString('DELETE', $sql);
    }

    public function test_closes_system_rows_of_done_declined_and_archived_and_leaves_the_rest(): void
    {
        $boardId = $this->insertBoard('demo', ['statuses_customized' => 1]);
        foreach (['open', 'planned', 'in_progress', 'done', 'declined', 'archived'] as $i => $key) {
            $this->conn->insert('board_statuses', [
                'board_id' => $boardId, 'status_key' => $key, 'category' => $key,
                'position' => $i, 'is_system' => 1, 'allow_votes' => 1,
            ]);
        }
        // Custom statuses in a decided category: their own flag must survive.
        $this->conn->insert('board_statuses', [
            'board_id' => $boardId, 'status_key' => 's1', 'category' => 'done',
            'position' => 10, 'is_system' => 0, 'allow_votes' => 1,
        ]);
        $this->conn->insert('board_statuses', [
            'board_id' => $boardId, 'status_key' => 's2', 'category' => 'declined',
            'position' => 11, 'is_system' => 0, 'allow_votes' => 0,
        ]);
        // A board that was never customised has no rows: nothing to update there.
        $otherBoard = $this->insertBoard('other');

        (new SqlFileMigration($this->path()))->up($this->conn);

        $flags = $this->conn->fetchAllKeyValue(
            'SELECT status_key, allow_votes FROM board_statuses WHERE board_id = ?',
            [$boardId],
        );
        $flags = array_map(intval(...), $flags);
        ksort($flags);
        self::assertSame(
            ['archived' => 0, 'declined' => 0, 'done' => 0, 'in_progress' => 1, 'open' => 1, 'planned' => 1, 's1' => 1, 's2' => 0],
            $flags,
        );
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_statuses WHERE board_id = ?', [$otherBoard]));
    }
}
