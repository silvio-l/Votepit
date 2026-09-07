<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\MigrationRunner;
use Votepit\Migrations\SqlFileMigration;

/**
 * Content/discovery comparison for the three account schema migrations
 * (0001_create_account_schema, 0002_add_boards_account_id,
 * 0003_seed_default_account). Deliberately runs NO SQL — same rationale
 * as BaselineMigrationTest: MySQL-specific DDL
 * (ENGINE=InnoDB, ALTER ... AFTER, ALTER ... DROP INDEX, CHECK constraints)
 * doesn't run against the SQLite test connection.
 *
 * Real execution (empty DB, self-host existing-data scenario with backfill,
 * idempotency across two migrate() calls, UNIQUE(account_id, slug) across
 * two accounts) was manually verified against a real MySQL 8 instance.
 */
final class AccountSchemaMigrationTest extends TestCase
{
    private const VERSIONS = [
        '0001_create_account_schema',
        '0002_add_boards_account_id',
        '0003_seed_default_account',
    ];

    private function coreDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_all_three_migration_files_exist_with_expected_versions(): void
    {
        foreach (self::VERSIONS as $version) {
            $path = $this->coreDir() . "/migrations/{$version}.sql";
            self::assertFileExists($path);
            self::assertSame($version, (new SqlFileMigration($path))->version());
        }
    }

    public function test_real_migrations_dir_discovers_them_in_order_after_baseline(): void
    {
        $runner     = new MigrationRunner(self::createStub(\Doctrine\DBAL\Connection::class), $this->coreDir() . '/migrations');
        $migrations = array_map(static fn (\Votepit\Migrations\Migration $m): string => $m->version(), $runner->discover());

        // Slice instead of exact equality against the full list: later
        // migrations (0004-0006, see EmailHmacDdlMigrationTest for the
        // full list maintained there) shouldn't break this test
        // on every extension.
        self::assertSame(
            ['0000_baseline', ...self::VERSIONS],
            array_slice($migrations, 0, 1 + count(self::VERSIONS)),
        );
    }

    public function test_0001_creates_accounts_and_account_members_with_role_check(): void
    {
        $sql = $this->readMigration('0001_create_account_schema');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS accounts', $sql);
        self::assertStringContainsString('UNIQUE KEY uq_accounts_slug (slug)', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS account_members', $sql);
        self::assertStringContainsString('PRIMARY KEY (account_id, user_id)', $sql);
        self::assertStringContainsString("CHECK (role IN ('owner', 'moderator'))", $sql);
        self::assertStringContainsString('REFERENCES accounts(id) ON DELETE CASCADE', $sql);
        self::assertStringContainsString('REFERENCES users(id)    ON DELETE CASCADE', $sql);
    }

    public function test_0002_adds_nullable_account_id_and_status_to_boards(): void
    {
        $sql = $this->readMigration('0002_add_boards_account_id');

        self::assertStringContainsString(
            'ALTER TABLE boards ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER id',
            $sql,
        );
        self::assertStringContainsString(
            "ALTER TABLE boards ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active' AFTER account_id",
            $sql,
        );
    }

    /**
     * The DROP INDEX name must match the ACTUAL unique index from db/schema.sql
     * (uq_boards_slug) — otherwise the migration fails on a real
     * installation. Regression guard against renaming the index in
     * schema.sql in the future without updating 0003.
     */
    public function test_0003_drops_the_real_existing_boards_slug_index(): void
    {
        $schema = (string) file_get_contents($this->coreDir() . '/db/schema.sql');
        $matched = preg_match('/UNIQUE KEY (\w+) \(slug\)/', $schema, $m);
        self::assertSame(1, $matched, 'db/schema.sql: no UNIQUE KEY (slug) found on boards.');
        $realIndexName = $m[1];

        $migration = $this->readMigration('0003_seed_default_account');
        self::assertStringContainsString("ALTER TABLE boards DROP INDEX {$realIndexName}", $migration);
    }

    public function test_0003_seeds_default_account_idempotently_and_backfills_boards(): void
    {
        $sql = $this->readMigration('0003_seed_default_account');

        self::assertStringContainsString('WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE is_default = 1)', $sql);
        self::assertStringContainsString('UPDATE boards SET account_id =', $sql);
        self::assertStringContainsString('WHERE account_id IS NULL', $sql);
        self::assertStringContainsString('ALTER TABLE boards MODIFY account_id BIGINT UNSIGNED NOT NULL', $sql);
        self::assertStringContainsString('ADD UNIQUE KEY uq_boards_account_slug (account_id, slug)', $sql);
        self::assertStringContainsString(
            'ADD CONSTRAINT fk_boards_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE',
            $sql,
        );
    }

    public function test_0003_documents_the_non_atomic_ddl_caveat(): void
    {
        $sql = $this->readMigration('0003_seed_default_account');

        self::assertStringContainsString('NICHT atomar', $sql); // export-ok: comment-language (pins frozen migration text)
    }

    private function readMigration(string $version): string
    {
        return (string) file_get_contents($this->coreDir() . "/migrations/{$version}.sql");
    }
}
