<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Votepit\Config;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\TablePlanPolicy;
use Votepit\Extension\AppExtension;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;

/**
 * Test DB harness.
 *
 * Creates a fresh SQLite in-memory connection per test and applies a
 * lean, SQLite-compatible schema (users + login_tokens). Tests boot the
 * app via AppFactory::create($config, $conn, $mailer, $audit)
 * — the identical HTTP seam as production.
 *
 * SQLite instead of MySQL: no MySQL process needed; all repositories use
 * portable SQL (no MySQL-specific functions). The IP rate-limit layer
 * is inert in tests because REMOTE_ADDR is not set on the test request
 * (RateLimitMiddleware passes through immediately without an identity).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Connection $conn;

    /** @var non-empty-string */
    protected string $logFile;

    /** profile-avatar-social: throwaway per-test dir, never the real repo storage/. */
    protected string $avatarDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn      = $this->createSqliteConnection();
        $this->logFile   = sys_get_temp_dir() . '/votepit-test-audit-' . uniqid() . '.log';
        $this->avatarDir = sys_get_temp_dir() . '/votepit-test-avatars-' . uniqid();

        $this->applySchema($this->conn);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        if (is_dir($this->avatarDir)) {
            $files = glob($this->avatarDir . '/*');
            foreach ($files !== false ? $files : [] as $file) {
                unlink($file);
            }
            rmdir($this->avatarDir);
        }

        parent::tearDown();
    }

    /**
     * @param list<AppExtension>|null $extensions null = no extensions (Community default)
     * @return App<null>
     */
    protected function createApp(?Mailer $mailer = null, ?PlanPolicy $planPolicy = null, ?array $extensions = null): App
    {
        $audit = new AuditLogger($this->logFile);
        // Default to InMemoryMailer — no real SMTP delivery in tests.
        $resolvedMailer = $mailer ?? new InMemoryMailer();
        return AppFactory::create(
            $this->testConfig(),
            $this->conn,
            $resolvedMailer,
            $audit,
            avatarDirOverride: $this->avatarDir,
            // Synthetic tiers so the gating mechanism itself stays covered in
            // core even though production self-host runs unrestricted.
            planPolicy: $planPolicy ?? self::syntheticPlanPolicy(),
            extensions: $extensions ?? [],
        );
    }

    /**
     * Synthetic tier table used by the integration tests to exercise every
     * plan gate (board count, team size, Agent API, visibility, branding).
     * Tier names and numbers are test fixtures only — the Community Edition
     * ships no tiers (UnrestrictedPlanPolicy).
     *
     * - self-host: unlimited everything (matches the seeded default account)
     * - starter:   1 board, 1 member, no Agent API, public boards only, no branding fields
     * - team:      5 boards, 5 members, no Agent API, all visibilities, secondary_color/logo_url/intro
     * - business:  unlimited, Agent API on, all visibilities, all branding fields
     */
    public static function syntheticPlanPolicy(): TablePlanPolicy
    {
        return new TablePlanPolicy([
            'self-host' => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => PlanPolicy::ALL_BRANDING_FIELDS],
            'starter'   => ['board_limit' => 1, 'member_limit' => 1, 'agent_api' => false, 'visibilities' => ['public'], 'branding_fields' => []],
            'team'      => ['board_limit' => 5, 'member_limit' => 5, 'agent_api' => false, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => ['secondary_color', 'logo_url', 'intro']],
            'business'  => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => PlanPolicy::ALL_BRANDING_FIELDS],
        ], 'starter', 'business');
    }

    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                  => 'dev',
            'app_url'              => 'http://localhost:8000',
            'app_key'              => str_repeat('a', 64),
            'identity_server_key'  => self::identityServerKey(),
            'db'                   => ['name' => ':memory:'],
            'smtp'                 => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'       => 900,
        ]);
    }

    /**
     * Fixed test value for identity_server_key (ADR 0002) — analogous to the
     * fixed app_key = str_repeat('a', 64). Different letter (b instead of a)
     * so both secrets stay distinguishable in test fixtures.
     */
    protected static function identityServerKey(): string
    {
        return str_repeat('b', 64);
    }

    /** Reads all lines of the audit log file (for assertions on log content). */
    protected function readAuditLog(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private function createSqliteConnection(): Connection
    {
        $conn = DriverManager::getConnection($this->connectionParams());

        // SQLite disables FK enforcement by default (unlike MySQL/InnoDB,
        // which always enforces ON DELETE CASCADE/SET NULL/RESTRICT) — turn
        // it on so this portable test schema actually exercises the same
        // cascade behaviour as production (operator account/board
        // hard-delete relies on ON DELETE CASCADE doing the cleanup).
        $conn->executeStatement('PRAGMA foreign_keys = ON');

        return $conn;
    }

    /**
     * Overridable hook: a subclass may add a `wrapperClass` (a
     * Doctrine\DBAL\Connection subclass) to inject a failure into a specific
     * statement for a transactional-rollback test, without changing the
     * connection type for every other test.
     *
     * @return array<string, mixed>
     */
    protected function connectionParams(): array
    {
        return [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];
    }

    private function applySchema(Connection $conn): void
    {
        // SQLite-compatible subset of the MySQL schema (db/schema.sql).
        // Omitted: ENGINE, CHARSET, COLLATE, UNSIGNED, TINYINT(1),
        // FULLTEXT, ON DUPLICATE KEY UPDATE → not needed for the integration tests.

        // migrations/0001_create_account_schema.sql + 0003_seed_default_account.sql:
        // account schema + default account. boards.account_id is NOT NULL in the
        // target schema (see 0003) — the default account therefore already exists
        // here, so insertBoard() keeps working without an explicit account_id override.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS accounts (
                id                    INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                slug                  VARCHAR(64) NOT NULL,
                name                  VARCHAR(128) NOT NULL,
                plan                  VARCHAR(16) NOT NULL DEFAULT \'self-host\',
                board_limit           INTEGER NULL,
                member_limit          INTEGER NULL,
                is_default            INTEGER NOT NULL DEFAULT 0,
                -- Onboarding (Setup Wizard, migrations/0017_add_account_onboarding.sql):
                -- NULL until the wizard is finished or explicitly skipped.
                onboarding_completed_at DATETIME NULL,
                -- Product-improvement telemetry opt-out (self-host) —
                -- migrations/0035_add_telemetry_opt_in.sql. Default ON.
                telemetry_opted_in    INTEGER NOT NULL DEFAULT 1,
                telemetry_decided_at  DATETIME NULL,
                deletion_scheduled_at DATETIME NULL,
                -- Upgrade/downgrade/cancellation lifecycle: guards
                -- the export-reminder mail against resending on every login —
                -- see migrations/0016_add_board_freeze_and_deletion_reminder.sql.
                deletion_reminder_sent_at DATETIME NULL,
                confirmed_at          DATETIME NULL,
                -- Operator panel: reversible operator kill-switch,
                -- distinct from confirmed_at (spam gate) — see
                -- migrations/0013_add_operator_panel.sql.
                locked_at             DATETIME NULL,
                created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (slug)
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS account_members (
                account_id INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                role       VARCHAR(16) NOT NULL CHECK (role IN (\'owner\', \'admin\', \'moderator\', \'member\')),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, user_id),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
            )',
        );

        $conn->insert('accounts', [
            'slug'         => 'default',
            'name'         => 'Default Account',
            'plan'         => 'self-host',
            'is_default'   => 1,
            // Already confirmed — the same backfill semantics as
            // migrations/0011_add_account_confirmed_at.sql for the existing
            // self-host default account (the gate must never retroactively hide anything).
            'confirmed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            // Same backfill semantics as migration 0017 — the seeded default
            // account starts already onboarded so the wizard never interrupts
            // an established self-host install's own test fixtures.
            'onboarding_completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS boards (
                id                 INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id         INTEGER NOT NULL,
                status             VARCHAR(16) NOT NULL DEFAULT \'active\',
                -- migrations/0031_change_boards_visibility_default.sql: fail-secure
                -- default, defense-in-depth only (BoardRepository::create() always
                -- sets this column explicitly, never relies on the column default).
                visibility         VARCHAR(16) NOT NULL DEFAULT \'private\',
                -- Operator panel: reversible operator kill-switch,
                -- distinct from visibility (owner/plan-controlled) — see
                -- migrations/0013_add_operator_panel.sql.
                locked_at          DATETIME NULL,
                -- Upgrade/downgrade/cancellation lifecycle:
                -- downgrade-freeze, DISTINCT from locked_at — a frozen board
                -- stays publicly readable, only writes are rejected. See
                -- migrations/0016_add_board_freeze_and_deletion_reminder.sql.
                frozen_at          DATETIME NULL,
                slug               VARCHAR(64) NOT NULL,
                name               VARCHAR(128) NOT NULL,
                accent_color       VARCHAR(16) NOT NULL DEFAULT \'#3b82f6\',
                primary_color      VARCHAR(7) NULL,
                secondary_color    VARCHAR(7) NULL,
                logo_url           VARCHAR(512) NULL,
                intro              TEXT NULL,
                -- Branding tiers: Pro-only "Powered by Votepit"
                -- badge-hide switch — see migrations/0014_add_boards_hide_badge.sql.
                hide_badge         INTEGER NOT NULL DEFAULT 0,
                moderation_enabled INTEGER NOT NULL DEFAULT 1,
                is_default         INTEGER NOT NULL DEFAULT 0,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (account_id, slug),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_blocklist (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id   INTEGER NOT NULL,
                word       VARCHAR(200) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id, word),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

        // migrations/0006_finalize_email_hmac_column.sql, ADR 0002:
        // email_hmac is the only identity column (NOT NULL + UNIQUE), the
        // plaintext email column has been removed — this test schema depicts the
        // end state after 0006. AppFactory.php has read/written exclusively
        // email_hmac since this wave (UserRepository::findByEmailHmac()/
        // create(), Config::isAdminEmailHmac()).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS users (
                id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                -- opaque, non-sequential client-facing handle (migrations/
                -- 0036-0038_*_user_public_id*): random, not derived from id,
                -- safe to display — the raw id above stays internal-only.
                public_id     CHAR(10) NOT NULL,
                email_hmac    CHAR(64) NOT NULL,
                is_admin      INTEGER NOT NULL DEFAULT 0,
                -- Operator panel: platform-wide super-admin flag,
                -- separate from is_admin (installation-wide, self-promotable
                -- via admin_emails) and account_members.role (account-scoped)
                -- — see migrations/0013_add_operator_panel.sql. No self-service
                -- promotion path exists anywhere in the app.
                is_operator   INTEGER NOT NULL DEFAULT 0,
                -- lesser, non-singleton support tier (migrations/0039_add_
                -- user_is_support_column.sql) — see AuthZMiddleware::support().
                is_support    INTEGER NOT NULL DEFAULT 0,
                -- dedicated test/QA account, excluded from Matomo + rate
                -- limits (migrations/0042_add_user_is_test_account_column.sql).
                is_test_account INTEGER NOT NULL DEFAULT 0,
                is_blocked    INTEGER NOT NULL DEFAULT 0,
                token_version INTEGER NOT NULL DEFAULT 0,
                -- Password + TOTP 2FA: all NULL by default (opt-in,
                -- see migrations/0018_add_password_and_totp.sql).
                password_hash          VARCHAR(255) NULL,
                totp_secret_encrypted  VARCHAR(255) NULL,
                totp_enabled_at        DATETIME NULL,
                -- profile-avatar-social (migrations/0019_add_user_avatar_and_social_links.sql):
                -- opaque server-generated filename, NULL = no avatar set.
                avatar_filename VARCHAR(40) NULL,
                -- profile-visibility feature (migrations/0021_add_user_
                -- profile_visibility.sql): default 0 = anonymous.
                profile_visible INTEGER NOT NULL DEFAULT 0,
                -- optional public display name (migrations/0022_add_user_
                -- username.sql): username_lower is application-maintained
                -- (never a DB-generated column) so this test schema write
                -- path matches production exactly.
                username        VARCHAR(30) NULL,
                username_lower  VARCHAR(30) NULL,
                -- profile-avatar-social security redesign (migrations/0020_
                -- replace_social_links_with_fixed_fields.sql): 4 fixed, named
                -- social identifiers, each independently NULL = "not set".
                -- Never a URL — SocialLinkValidator validates each as a bare
                -- domain/handle/username; the URL is built server-side only.
                website_domain  VARCHAR(253) NULL,
                x_handle        VARCHAR(15)  NULL,
                youtube_handle  VARCHAR(30)  NULL,
                github_username VARCHAR(39)  NULL,
                -- notification-preferences (migrations/0029_add_notification_
                -- email_preferences.sql): notification_email is set ONLY via
                -- the confirm-link flow — non-NULL always means confirmed.
                notification_email        VARCHAR(254) NULL,
                notify_idea_comment_inapp INTEGER NOT NULL DEFAULT 1,
                notify_idea_comment_email INTEGER NOT NULL DEFAULT 0,
                notify_thread_reply_inapp INTEGER NOT NULL DEFAULT 1,
                notify_thread_reply_email INTEGER NOT NULL DEFAULT 0,
                -- idea-status-follow-notification (migrations/0034_add_idea_
                -- status_notification.sql): mirrors the four columns above,
                -- independent per-event-type flags.
                notify_idea_status_inapp  INTEGER NOT NULL DEFAULT 1,
                notify_idea_status_email  INTEGER NOT NULL DEFAULT 0,
                -- operator notification preferences (migrations/0045_add_
                -- operator_notification_preferences.sql): same independent
                -- in-app/email pattern, operator/support-only in practice.
                notify_abuse_report_inapp   INTEGER NOT NULL DEFAULT 1,
                notify_abuse_report_email   INTEGER NOT NULL DEFAULT 0,
                notify_support_ticket_inapp INTEGER NOT NULL DEFAULT 1,
                notify_support_ticket_email INTEGER NOT NULL DEFAULT 0,
                verified_at   DATETIME NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (email_hmac),
                UNIQUE (username_lower),
                UNIQUE (public_id)
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS totp_backup_codes (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                code_hash  CHAR(64) NOT NULL,
                used_at    DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS login_tokens (
                id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                token_hash  CHAR(64) NOT NULL,
                purpose     VARCHAR(32) NOT NULL DEFAULT \'login\',
                expires_at  DATETIME NOT NULL,
                used_at     DATETIME NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        // notification-preferences (migrations/0029_add_notification_email_
        // preferences.sql): pending confirmation tokens for
        // users.notification_email, same token-crypto shape as login_tokens.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS notification_email_verifications (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                email      VARCHAR(254) NOT NULL,
                token_hash CHAR(64)     NOT NULL,
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS rate_limits (
                bucket            VARCHAR(128) NOT NULL,
                window_seconds    INTEGER NOT NULL,
                count             INTEGER NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (bucket)
            )',
        );

        // migrations/0048_add_cron_heartbeats.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS cron_heartbeats (
                job_name    VARCHAR(64)  NOT NULL,
                last_run_at DATETIME     NOT NULL,
                status      VARCHAR(16)  NOT NULL,
                detail      VARCHAR(255) NOT NULL DEFAULT \'\',
                PRIMARY KEY (job_name)
            )',
        );

        // migrations/0030_add_slug_tombstones.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS slug_tombstones (
                id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                scope         VARCHAR(16) NOT NULL,
                account_id    INTEGER NULL,
                slug          VARCHAR(64) NOT NULL,
                tombstoned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at    DATETIME NOT NULL
            )',
        );

        // ideas table (portable subset; without FULLTEXT/ENGINE/UNSIGNED).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS ideas (
                id               INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id         INTEGER NOT NULL,
                author_id        INTEGER NOT NULL,
                title            VARCHAR(200) NOT NULL,
                title_normalized VARCHAR(200) NOT NULL DEFAULT \'\',
                body             TEXT NOT NULL,
                status           VARCHAR(16) NOT NULL DEFAULT \'open\',
                is_pinned        INTEGER NOT NULL DEFAULT 0,
                merged_into_id   INTEGER NULL,
                score_cache      INTEGER NOT NULL DEFAULT 0,
                view_count       INTEGER NOT NULL DEFAULT 0,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (board_id)  REFERENCES boards(id)  ON DELETE CASCADE,
                FOREIGN KEY (author_id) REFERENCES users(id)   ON DELETE RESTRICT
            )',
        );

        // Read path: votes + comments. Currently only display aggregates
        // (score/consensus/comment count); the mutation endpoints follow
        // later. Portable subset (without UNSIGNED/ENGINE).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS votes (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                idea_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                value      INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (idea_id, user_id),
                FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS comments (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                idea_id    INTEGER NOT NULL,
                author_id  INTEGER NOT NULL,
                body       TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                -- comment edit window (migrations/0027_add_comment_edit_
                -- window.sql): NULL until first edit, then last-edited time.
                edited_at  DATETIME NULL,
                FOREIGN KEY (idea_id)   REFERENCES ideas(id)  ON DELETE CASCADE,
                FOREIGN KEY (author_id) REFERENCES users(id)  ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS app_settings (
                "key"      VARCHAR(128) NOT NULL,
                value      TEXT         NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY ("key")
            )',
        );

        // blocked_users (account-wide + later board-scoped
        // user block, a shared model — see migrations/0008_create_blocked_users.sql).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS blocked_users (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                board_id   INTEGER NULL,
                created_by INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
                FOREIGN KEY (board_id)   REFERENCES boards(id)   ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE RESTRICT
            )',
        );

        // invites (account-scoped, hashed-token pending invitation —
        // see migrations/0009_create_invites.sql for the full rationale).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS invites (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                invited_by INTEGER NOT NULL,
                role       VARCHAR(16) NOT NULL DEFAULT \'moderator\' CHECK (role IN (\'member\', \'moderator\', \'admin\')),
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at    DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
                FOREIGN KEY (invited_by) REFERENCES users(id)    ON DELETE RESTRICT
            )',
        );

        // api_tokens (account-scoped bearer token, grants a SET of boards via
        // api_token_boards, each with its own scope — see
        // migrations/0010_create_api_tokens.sql,
        // 0044_rebuild_api_tokens_account_scoped.sql and
        // 0047_add_per_board_api_token_scope.sql for the full rationale).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS api_tokens (
                id                 INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id         INTEGER NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                label              VARCHAR(100) NOT NULL,
                token_hash         CHAR(64) NOT NULL,
                last_used_at       DATETIME NULL,
                revoked_at         DATETIME NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (token_hash),
                FOREIGN KEY (account_id)         REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS api_token_boards (
                token_id INTEGER NOT NULL,
                board_id INTEGER NOT NULL,
                scope    VARCHAR(10) NOT NULL DEFAULT \'write\',
                PRIMARY KEY (token_id, board_id),
                FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE,
                FOREIGN KEY (board_id) REFERENCES boards(id)     ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_smtp_settings (
                id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id    INTEGER NOT NULL,
                host        VARCHAR(255) NOT NULL DEFAULT \'\',
                port        INTEGER NOT NULL DEFAULT 587,
                user        VARCHAR(254) NOT NULL DEFAULT \'\',
                pass        TEXT NULL,
                encryption  VARCHAR(8) NOT NULL DEFAULT \'tls\',
                from_email  VARCHAR(254) NOT NULL DEFAULT \'\',
                from_name   VARCHAR(128) NOT NULL DEFAULT \'Votepit\',
                verify_peer INTEGER NOT NULL DEFAULT 1,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

        // Operator panel: abuse_reports — DSA Art. 16 report intake
        // pipeline (see migrations/0013_add_operator_panel.sql for the full
        // rationale). SQLite subset: no CHECK-enforced status enum (matches
        // the rest of this test schema's portability trade-off).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS abuse_reports (
                id                 INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id         INTEGER NULL,
                board_id           INTEGER NULL,
                idea_id            INTEGER NULL,
                target_url         VARCHAR(512) NOT NULL,
                reason             TEXT NOT NULL,
                reporter_email_enc TEXT NULL,
                status             VARCHAR(16) NOT NULL DEFAULT \'open\',
                reviewed_by        INTEGER NULL,
                reviewed_at        DATETIME NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id)  REFERENCES accounts(id) ON DELETE SET NULL,
                FOREIGN KEY (board_id)    REFERENCES boards(id)   ON DELETE SET NULL,
                FOREIGN KEY (idea_id)     REFERENCES ideas(id)    ON DELETE SET NULL,
                FOREIGN KEY (reviewed_by) REFERENCES users(id)    ON DELETE SET NULL
            )',
        );

        // Support/FAQ (support-feature, see
        // migrations/0023_add_support_and_faq.sql for the full rationale).
        // SQLite subset: no CHECK-enforced enums, same portability trade-off
        // as abuse_reports above.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS support_requests (
                id                 INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id         INTEGER NOT NULL,
                user_id            INTEGER NOT NULL,
                category           VARCHAR(32) NOT NULL,
                subject            VARCHAR(200) NOT NULL,
                status             VARCHAR(16) NOT NULL DEFAULT \'open\',
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
            )',
        );

        // migrations/0026_add_support_messages.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS support_messages (
                id              INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                request_id      INTEGER NOT NULL,
                author_type     VARCHAR(16) NOT NULL,
                author_user_id  INTEGER NOT NULL,
                body            TEXT NOT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (request_id)     REFERENCES support_requests(id) ON DELETE CASCADE,
                FOREIGN KEY (author_user_id) REFERENCES users(id)            ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS faq_entries (
                id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                category      VARCHAR(32) NOT NULL,
                question_de   TEXT NOT NULL,
                question_en   TEXT NOT NULL,
                answer_de     TEXT NOT NULL,
                answer_en     TEXT NOT NULL,
                sort_order    INTEGER NOT NULL DEFAULT 0,
                is_published  INTEGER NOT NULL DEFAULT 1,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        // Notifications (migrations/0024_add_notifications_remove_support_email.sql,
        // migrations/0028_add_user_scoped_notifications.sql for the full
        // rationale). SQLite subset: no CHECK-enforced enums, same
        // portability trade-off as abuse_reports/support_requests above.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS notifications (
                id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                scope       VARCHAR(16) NOT NULL,
                account_id  INTEGER NULL,
                user_id     INTEGER NULL,
                type        VARCHAR(32) NOT NULL,
                title       VARCHAR(200) NOT NULL,
                body        TEXT NOT NULL,
                link_path   VARCHAR(300) NULL,
                created_by  INTEGER NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS notification_reads (
                notification_id INTEGER NOT NULL,
                user_id          INTEGER NOT NULL,
                read_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (notification_id, user_id),
                FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
            )',
        );

        // migrations/0025_add_notification_dismissals.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS notification_dismissals (
                notification_id INTEGER NOT NULL,
                user_id          INTEGER NOT NULL,
                dismissed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (notification_id, user_id),
                FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
            )',
        );
    }

    // -------------------------------------------------------------------------
    // Shared seed helpers
    // -------------------------------------------------------------------------

    /**
     * Seeds a board; returns its ID.
     * Method from the shared harness — does not collide with private
     * seedBoard() methods in older subclasses.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertBoard(string $slug = 'demo', array $overrides = []): int
    {
        $this->conn->insert('boards', array_merge([
            'account_id' => $this->defaultAccountId(),
            'slug'       => $slug,
            'name'       => 'Demo Board',
            // Explicit, not relying on the column default (which is now
            // 'private', migration 0031) — most fixtures using this helper
            // are unrelated to visibility and expect an anon-readable board,
            // exactly as before that migration. Pass visibility via
            // $overrides for tests that specifically exercise the gate.
            'visibility' => 'public',
            'is_default' => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Returns the ID of the default account seeded in applySchema()
     * (implicit fallback for insertBoard() callers without an
     * explicit account_id override).
     */
    protected function defaultAccountId(): int
    {
        return (int) $this->conn->fetchOne('SELECT id FROM accounts WHERE is_default = 1 LIMIT 1');
    }

    /** Slug counterpart to defaultAccountId() — same seeded default account. */
    protected function defaultAccountSlug(): string
    {
        return (string) $this->conn->fetchOne('SELECT slug FROM accounts WHERE is_default = 1 LIMIT 1');
    }

    /**
     * Seeds an additional (non-default) account; returns its ID.
     * For cross-tenant leak tests — the default account already exists
     * from applySchema(), this creates a second, independent one.
     *
     * confirmed_at is already set (confirmed) by default — the normal case
     * for practically all existing tests, which expect an immediately public
     * board. Tests for the confirm-before-public gate
     * override it explicitly with `'confirmed_at' => null`.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertAccount(array $overrides = []): int
    {
        $this->conn->insert('accounts', array_merge([
            'slug'         => 'acct-' . bin2hex(random_bytes(4)),
            'name'         => 'Test Account',
            'plan'         => 'self-host',
            'is_default'   => 0,
            'confirmed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds an account_members membership (accountAdmin()).
     */
    protected function insertAccountMember(int $accountId, int $userId, string $role): void
    {
        $this->conn->insert('account_members', [
            'account_id' => $accountId,
            'user_id'    => $userId,
            'role'       => $role,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Seeds a pending invites row; returns its ID.
     * token_hash is a SHA-256 hex string — tests that need the corresponding
     * plaintext token (accept flow) compute it themselves via
     * TokenVault and pass the hash via override.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertInvite(
        int $accountId,
        int $userId,
        int $invitedBy,
        string $tokenHash,
        array $overrides = [],
    ): int {
        $this->conn->insert('invites', array_merge([
            'account_id' => $accountId,
            'user_id'    => $userId,
            'invited_by' => $invitedBy,
            'role'       => 'moderator',
            'token_hash' => $tokenHash,
            'expires_at' => (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'),
            'used_at'    => null,
            'revoked_at' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a verified user; returns its ID.
     *
     * email_hmac is computed from $email by default (IdentityHasher with the
     * fixed test identity_server_key) and is NOT NULL (post-0006 end state,
     * see applySchema()) — tests that want to assert against a different
     * email_hmac can override it via $overrides.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertUser(string $email = 'user@example.com', array $overrides = []): int
    {
        $this->conn->insert('users', array_merge([
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'      => 0,
            'is_operator'   => 0,
            'is_support'    => 0,
            'is_test_account' => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds an idea; returns its ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedIdea(int $boardId, int $authorId, string $title = 'Test idea', array $overrides = []): int
    {
        $this->conn->insert('ideas', array_merge([
            'board_id'        => $boardId,
            'author_id'       => $authorId,
            'title'           => $title,
            'title_normalized' => mb_strtolower($title, 'UTF-8'),
            'body'            => 'Default description.',
            'status'          => 'open',
            'is_pinned'       => 0,
            'score_cache'     => 0,
            'view_count'      => 0,
            'created_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a vote (votes row). Does NOT maintain score_cache automatically
     * (ADR-3 amendment: no DB triggers) — tests set score_cache via
     * seedIdea() override where needed. Returns the vote ID.
     */
    /** @param array<string, mixed> $overrides */
    protected function seedVote(int $ideaId, int $userId, int $value, array $overrides = []): int
    {
        $this->conn->insert('votes', array_merge([
            'idea_id'    => $ideaId,
            'user_id'    => $userId,
            'value'      => $value,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a comment; returns its ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedComment(int $ideaId, int $authorId, string $body = 'Test comment.', array $overrides = []): int
    {
        $this->conn->insert('comments', array_merge([
            'idea_id'    => $ideaId,
            'author_id'  => $authorId,
            'body'       => $body,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds an api_tokens row; returns its ID.
     * token_hash is a SHA-256 hex string — tests that need the corresponding
     * plaintext token (bearer auth flow) compute it themselves via
     * TokenVault and pass the hash via override.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertApiToken(
        int $accountId,
        int $boardId,
        int $createdByUserId,
        string $tokenHash,
        string $label = 'Test-Token',
        array $overrides = [],
        string $scope = 'write',
    ): int {
        $this->conn->insert('api_tokens', array_merge([
            'account_id'         => $accountId,
            'created_by_user_id' => $createdByUserId,
            'label'              => $label,
            'token_hash'         => $tokenHash,
            'last_used_at'       => null,
            'revoked_at'         => null,
            'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        $tokenId = (int) $this->conn->lastInsertId();

        $this->conn->insert('api_token_boards', [
            'token_id' => $tokenId,
            'board_id' => $boardId,
            'scope'    => $scope,
        ]);

        return $tokenId;
    }

    /**
     * Creates a valid signed session cookie for the given user.
     */
    protected function sessionCookie(int $userId, int $tokenVersion = 0): string
    {
        $appKey   = str_repeat('a', 64);
        $sessions = new \Votepit\Security\SessionService($appKey, 3600, false);
        return $sessions->sign(['uid' => $userId, 'v' => $tokenVersion]);
    }

    /**
     * Tier enforcement: sets an account's plan directly in the
     * DB — test helper to check plan-dependent gates (board count, team size,
     * Agent API, visibility) against the default account (self-host routing
     * always resolves to this one in tests), without spinning up the full
     * cloud routing machinery.
     */
    protected function setAccountPlan(int $accountId, string $plan): void
    {
        $this->conn->update('accounts', ['plan' => $plan], ['id' => $accountId]);
    }

    /**
     * Operator panel: seeds an abuse_reports row; returns its ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertAbuseReport(string $targetUrl = '/demo/ideas/1', string $reason = 'Spam', array $overrides = []): int
    {
        $this->conn->insert('abuse_reports', array_merge([
            'target_url' => $targetUrl,
            'reason'     => $reason,
            'status'     => 'open',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a support_requests row plus its opening customer message;
     * returns the ticket ID.
     *
     * @param array<string, mixed> $overrides applied to the support_requests row
     */
    protected function insertSupportRequest(int $accountId, int $userId, array $overrides = []): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('support_requests', array_merge([
            'account_id' => $accountId,
            'user_id'    => $userId,
            'category'   => 'technical',
            'subject'    => 'Test request',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        $requestId = (int) $this->conn->lastInsertId();
        $this->insertSupportMessage($requestId, 'customer', $userId, 'A test message with enough characters.');

        return $requestId;
    }

    /**
     * Seeds a support_messages row on an existing ticket; returns its ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertSupportMessage(
        int $requestId,
        string $authorType,
        int $authorUserId,
        string $body,
        array $overrides = [],
    ): int {
        $this->conn->insert('support_messages', array_merge([
            'request_id'     => $requestId,
            'author_type'    => $authorType,
            'author_user_id' => $authorUserId,
            'body'           => $body,
            'created_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a faq_entries row; returns its ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertFaqEntry(array $overrides = []): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('faq_entries', array_merge([
            'category'     => 'technical',
            'question_de'  => 'Test question?',
            'question_en'  => 'Test question?',
            'answer_de'    => 'Test answer.',
            'answer_en'    => 'Test answer.',
            'sort_order'   => 0,
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a notifications row; returns its ID. Defaults to an
     * account-scoped 'support_reply' notification (the more common case in
     * tests); pass ['scope' => 'broadcast', 'account_id' => null, ...] for a
     * broadcast announcement.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertNotification(?int $accountId, array $overrides = []): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('notifications', array_merge([
            'scope'      => 'account',
            'account_id' => $accountId,
            'type'       => 'support_reply',
            'title'      => 'Test notification',
            'body'       => 'A test text.',
            'link_path'  => null,
            'created_by' => null,
            'created_at' => $now,
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }
}
