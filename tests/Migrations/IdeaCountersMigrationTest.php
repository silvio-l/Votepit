<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use Votepit\Migrations\SqlFileMigration;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * 0072_add_idea_counters: the ALTERs are MySQL-specific (… AFTER …) and are
 * only content-checked (same rationale as AccountConfirmedAtMigrationTest);
 * the backfill UPDATE is portable SQL and is executed against the SQLite
 * test schema to prove it reproduces exactly the aggregates the replaced
 * COUNT subqueries computed.
 */
final class IdeaCountersMigrationTest extends IntegrationTestCase
{
    private const VERSION = '0072_add_idea_counters';

    private function path(): string
    {
        return dirname(__DIR__, 2) . '/migrations/' . self::VERSION . '.sql';
    }

    private function backfillStatement(): string
    {
        $statements = array_map(trim(...), explode(";\n", (string) file_get_contents($this->path())));
        $updates    = array_values(array_filter($statements, static fn (string $s): bool => str_starts_with($s, 'UPDATE ideas')));
        self::assertCount(1, $updates);

        return rtrim($updates[0], ';');
    }

    public function test_migration_adds_the_three_counter_columns(): void
    {
        self::assertSame(self::VERSION, (new SqlFileMigration($this->path()))->version());

        $sql = (string) file_get_contents($this->path());
        self::assertStringContainsString('ALTER TABLE ideas ADD COLUMN up_count INT NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('ALTER TABLE ideas ADD COLUMN down_count INT NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('ALTER TABLE ideas ADD COLUMN comment_count INT NOT NULL DEFAULT 0', $sql);
    }

    public function test_backfill_reproduces_the_live_aggregates(): void
    {
        $boardId = $this->insertBoard('ctr-backfill');
        $author  = $this->insertUser('ctr-backfill-author@example.com');
        $voterA  = $this->insertUser('ctr-backfill-a@example.com');
        $voterB  = $this->insertUser('ctr-backfill-b@example.com');
        $busy    = $this->seedIdea($boardId, $author, 'Busy');
        $quiet   = $this->seedIdea($boardId, $author, 'Quiet');

        $this->seedVote($busy, $voterA, 1);
        $this->seedVote($busy, $voterB, -1);
        $this->seedVote($busy, $author, 1);
        $this->seedVote($busy, 0, 1, ['user_id' => null, 'voter_token_hash' => 'tok-backfill']);
        $this->seedComment($busy, $voterA);
        $this->seedComment($busy, $voterB, 'Hidden', ['deleted_at' => '2026-01-01 00:00:00']);

        // Simulate a pre-0072 database: columns exist with their default 0.
        $this->conn->executeStatement('UPDATE ideas SET up_count = 0, down_count = 0, comment_count = 0');

        $this->conn->executeStatement($this->backfillStatement());

        $rows = $this->conn->fetchAllAssociativeIndexed('SELECT id, up_count, down_count, comment_count FROM ideas');
        self::assertSame([3, 1, 1], array_map(intval(...), array_values($rows[$busy])));
        self::assertSame([0, 0, 0], array_map(intval(...), array_values($rows[$quiet])));
    }
}
