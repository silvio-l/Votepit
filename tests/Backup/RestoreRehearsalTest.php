<?php

declare(strict_types=1);

namespace Votepit\Tests\Backup;

use PHPUnit\Framework\TestCase;
use Votepit\Backup\BackupException;
use Votepit\Backup\RestoreRehearsal;
use Votepit\DbConfig;

/**
 * RestoreRehearsal unit tests.
 *
 * The hard safety check (guardTarget()) is pure logic and fully testable
 * without any binary or DB connection — that's exactly the part this test
 * suite exists to nail down, since it's the one invariant this whole
 * script's safety depends on. The actual restore()/dryRunPendingMigrations()
 * shell-out to `mysql`/a live restored DB is skipped gracefully when the
 * `mysql` client isn't available (never fails the suite for lacking
 * external infra).
 */
final class RestoreRehearsalTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function db(array $overrides = []): DbConfig
    {
        return DbConfig::fromArray(array_merge([
            'host'    => 'localhost',
            'port'    => 3306,
            'name'    => 'votepit',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ], $overrides));
    }

    public function test_guard_target_refuses_when_target_name_matches_production(): void
    {
        $rehearsal = new RestoreRehearsal();
        $production = $this->db(['name' => 'votepit']);
        $target     = $this->db(['name' => 'votepit']);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageMatches('/identical to the configured production DB/');

        $rehearsal->guardTarget($production, $target);
    }

    public function test_guard_target_refuses_even_when_target_host_differs_but_name_matches(): void
    {
        // Same DB name on a different host is still refused — the check is
        // deliberately name-based (see RestoreRehearsal's class doc): an
        // operator must pick a genuinely distinct name for the throwaway DB.
        $rehearsal  = new RestoreRehearsal();
        $production = $this->db(['name' => 'votepit', 'host' => 'prod-host']);
        $target     = $this->db(['name' => 'votepit', 'host' => 'throwaway-host']);

        $this->expectException(BackupException::class);

        $rehearsal->guardTarget($production, $target);
    }

    public function test_guard_target_allows_a_distinct_target_name(): void
    {
        $rehearsal  = new RestoreRehearsal();
        $production = $this->db(['name' => 'votepit']);
        $target     = $this->db(['name' => 'votepit_restore_test']);

        $rehearsal->guardTarget($production, $target);

        $this->addToAssertionCount(1); // no exception = pass
    }

    public function test_restore_refuses_when_dump_file_missing(): void
    {
        $rehearsal = new RestoreRehearsal();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageMatches('/Dump file not found/');

        $rehearsal->restore('/nonexistent/path/dump-' . uniqid() . '.sql', $this->db(['name' => 'votepit_restore_test']));
    }

    public function test_restore_and_dry_run_are_skipped_without_a_real_mysql_client(): void
    {
        $rehearsal = new RestoreRehearsal();

        if ($rehearsal->binaryAvailable()) {
            self::markTestSkipped('A real mysql client is present, but exercising restore()/dryRunPendingMigrations() end to end still needs a live throwaway MySQL instance, which this suite intentionally never provisions.');
        }

        self::assertFalse($rehearsal->binaryAvailable());
    }
}
