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
                '0026_add_support_messages',
                '0027_add_comment_edit_window',
                '0028_add_user_scoped_notifications',
                '0029_add_notification_email_preferences',
                '0030_add_slug_tombstones',
                '0031_change_boards_visibility_default',
                '0032_add_boards_featured_at',
                '0033_drop_boards_featured_at',
                '0034_add_idea_status_notification',
                '0035_add_telemetry_opt_in',
                '0036_add_user_public_id_column',
                '0037_backfill_user_public_id',
                '0038_finalize_user_public_id_column',
                '0039_add_user_is_support_column',
                '0040_enforce_single_operator',
                '0041_add_operator_scoped_notifications',
                '0042_add_user_is_test_account_column',
                '0043_add_account_admin_role',
                '0044_rebuild_api_tokens_account_scoped',
                '0045_add_operator_notification_preferences',
                '0046_add_account_member_role',
                '0047_add_per_board_api_token_scope',
                '0048_add_cron_heartbeats',
                '0049_add_ideas_view_count',
                '0050_add_comment_reactions',
                '0051_create_idea_tags',
                '0052_add_oauth_login',
                '0053_add_board_webhooks',
                '0054_create_content_translations',
                '0055_add_boards_translation_enabled',
                '0056_add_ideas_dates_and_archived_status',
                '0057_extend_content_translations_entity_types',
                '0058_add_legal_operator_identity',
                '0059_create_content_moderation_actions',
                '0060_add_idea_moderation_columns',
                '0061_create_content_moderation_appeals',
                '0062_create_account_member_standing',
                '0063_add_boards_compliance_freeze',
                '0064_add_legal_operator_contact',
                '0065_add_cron_manager_runs',
                '0066_add_totp_replay_protection_and_backup_code_hashing',
                '0067_add_circuit_breakers',
                '0068_add_anonymous_voting',
                '0069_replace_voter_email_hash_with_encryption',
                '0070_add_voter_identity_links',
                '0071_allow_multiple_operators',
                '0072_add_idea_counters',
                '0073_create_board_statuses',
                '0074_add_user_self_service_deletion',
                '0075_add_weekly_owner_digest',
                '0076_add_idea_merge_columns',
                '0077_add_idea_imports',
                '0078_add_board_widgets',
                '0079_add_board_chat_integrations',
                '0080_add_issue_tracker_links',
                '0081_add_api_token_moderate_scope',
                '0082_add_board_phase_windows',
                '0083_add_idea_premoderation',
                '0084_add_anonymous_idea_submission',
                '0088_add_mcp_oauth',
                '0089_add_trusted_authors',
                '0090_add_cron_jobs',
                '0091_create_idea_status_decisions',
                '0092_add_board_assets',
                '0093_add_board_pro_branding',
                '0094_add_account_brand_kit',
                '0095_drop_board_brand_overrides',
                '0096_drop_account_brand_kits',
                '0097_add_board_cover_pattern',
                '0098_add_upload_terms_consents',
                '0099_add_vote_reasons',
                '0100_add_board_asset_ink',
                '0101_upload_terms_consent_pseudonymisation',
                '0102_add_board_theme_mode',
                '0103_add_user_last_seen_at',
                '0104_create_idea_follows',
                '0105_create_participant_mail_preferences',
                '0106_create_participant_mail_queue',
                '0107_create_participant_digest_sends',
                '0108_create_participant_guest_suppressions',
                '0109_create_participant_new_ideas_state',
                '0110_create_operator_nudge_log',
                '0111_add_operator_nudge_preferences',
                '0112_add_marketing_consent',
                '0113_create_worklist_dismissals',
                '0114_close_votes_on_decided_system_statuses',
                '0115_add_boards_status_reason_required',
                '0116_add_board_brand_accent_and_logo_plate',
                '0117_add_api_token_manage_scope',
                '0118_add_account_member_suspension',
                '0119_add_update_notice_preference',
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
