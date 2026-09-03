<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\MigrationRunner;
use Votepit\Migrations\SqlFileMigration;

/**
 * Content/discovery comparison for the MySQL-specific DDL of the email HMAC
 * migrations (0004_add_email_hmac_column, 0006_finalize_email_hmac_column).
 * Deliberately runs NO SQL — same rationale as
 * AccountSchemaMigrationTest: MySQL-specific DDL (MODIFY, DROP INDEX)
 * doesn't run against the SQLite test connection. The pure backfill LOGIC of
 * the in-between 0005 (portable SQL, no DDL) is instead verified
 * end-to-end against SQLite in EmailHmacMigrationTest.
 */
final class EmailHmacDdlMigrationTest extends TestCase
{
    private function coreDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_0004_and_0006_files_exist_with_expected_versions(): void
    {
        foreach (['0004_add_email_hmac_column', '0006_finalize_email_hmac_column'] as $version) {
            $path = $this->coreDir() . "/migrations/{$version}.sql";
            self::assertFileExists($path);
            self::assertSame($version, (new SqlFileMigration($path))->version());
        }
    }

    public function test_real_migrations_dir_discovers_all_versions_in_order(): void
    {
        $runner     = new MigrationRunner(self::createStub(\Doctrine\DBAL\Connection::class), $this->coreDir() . '/migrations');
        $migrations = array_map(static fn (\Votepit\Migrations\Migration $m): string => $m->version(), $runner->discover());

        self::assertSame(
            [
                '0000_baseline',
                '0001_create_account_schema',
                '0002_add_boards_account_id',
                '0003_seed_default_account',
                '0004_add_email_hmac_column',
                '0005_backfill_email_hmac',
                '0006_finalize_email_hmac_column',
                '0007_backfill_admin_account_membership',
                '0008_create_blocked_users',
                '0009_create_invites',
                '0010_create_api_tokens',
                '0011_add_account_confirmed_at',
                '0012_add_boards_visibility',
                '0013_add_operator_panel',
                '0014_add_boards_hide_badge',
                '0016_add_board_freeze_and_deletion_reminder',
                '0017_add_account_onboarding',
                '0018_add_password_and_totp',
                '0019_add_user_avatar_and_social_links',
                '0020_replace_social_links_with_fixed_fields',
                '0021_add_user_profile_visibility',
                '0022_add_user_username',
                '0023_add_support_and_faq',
                '0024_add_notifications_remove_support_email',
                '0025_add_notification_dismissals',
            ],
            $migrations,
        );
    }

    public function test_0004_adds_a_nullable_email_hmac_column_after_email(): void
    {
        $sql = $this->readSqlMigration('0004_add_email_hmac_column');

        self::assertStringContainsString(
            'ALTER TABLE users ADD COLUMN email_hmac CHAR(64) NULL AFTER email',
            $sql,
        );
    }

    /**
     * The DROP INDEX name must match the ACTUAL unique index from db/schema.sql
     * (uq_users_email) — otherwise the migration fails on a real
     * installation. Regression guard analogous to
     * AccountSchemaMigrationTest::test_0003_drops_the_real_existing_boards_slug_index.
     */
    public function test_0006_drops_the_real_existing_users_email_index(): void
    {
        $schema  = (string) file_get_contents($this->coreDir() . '/db/schema.sql');
        $matched = preg_match('/UNIQUE KEY (\w+) \(email\)/', $schema, $m);
        self::assertSame(1, $matched, 'db/schema.sql: no UNIQUE KEY (email) found on users.');
        $realIndexName = $m[1];

        $migration = $this->readSqlMigration('0006_finalize_email_hmac_column');
        self::assertStringContainsString("ALTER TABLE users DROP INDEX {$realIndexName}", $migration);
    }

    public function test_0006_finalizes_email_hmac_and_drops_email(): void
    {
        $sql = $this->readSqlMigration('0006_finalize_email_hmac_column');

        self::assertStringContainsString('ALTER TABLE users MODIFY email_hmac CHAR(64) NOT NULL', $sql);
        self::assertStringContainsString('ADD UNIQUE KEY uq_users_email_hmac (email_hmac)', $sql);
        self::assertStringContainsString('ALTER TABLE users DROP COLUMN email', $sql);
    }

    /**
     * boards.hide_badge (branding tiers) — same DDL-only-test
     * discipline as 0004/0006 above (MySQL `ALTER ... AFTER` doesn't run
     * against the SQLite test connection).
     */
    public function test_0014_adds_a_hide_badge_column_after_intro(): void
    {
        $sql = $this->readSqlMigration('0014_add_boards_hide_badge');

        self::assertStringContainsString(
            'ALTER TABLE boards ADD COLUMN hide_badge TINYINT(1) NOT NULL DEFAULT 0 AFTER intro',
            $sql,
        );
    }

    private function readSqlMigration(string $version): string
    {
        return (string) file_get_contents($this->coreDir() . "/migrations/{$version}.sql");
    }
}
