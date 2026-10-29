<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Doctrine\DBAL\Configuration;
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
use Votepit\Monitoring\ErrorReporter;
use Votepit\Security\IdentityHasher;
use Votepit\Security\OAuth\OAuthHttpClient;
use Votepit\Security\Password\HibpHttpClient;
use Votepit\Security\Password\InMemoryHibpHttpClient;
use Votepit\Security\PublicIdGenerator;
use Votepit\Security\Turnstile\TurnstileHttpClient;

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

    /** P-28: throwaway per-test dir for board imagery files. */
    protected string $boardAssetDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn      = $this->createSqliteConnection();
        $this->logFile   = sys_get_temp_dir() . '/votepit-test-audit-' . uniqid() . '.log';
        $this->avatarDir = sys_get_temp_dir() . '/votepit-test-avatars-' . uniqid();
        $this->boardAssetDir = sys_get_temp_dir() . '/votepit-test-board-assets-' . uniqid();

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

        if (is_dir($this->boardAssetDir)) {
            $files = glob($this->boardAssetDir . '/*');
            foreach ($files !== false ? $files : [] as $file) {
                unlink($file);
            }
            rmdir($this->boardAssetDir);
        }

        parent::tearDown();
    }

    /**
     * @param list<AppExtension>|null $extensions null = no extensions (Community default)
     * @return App<null>
     */
    protected function createApp(
        ?Mailer $mailer = null,
        ?PlanPolicy $planPolicy = null,
        ?array $extensions = null,
        ?OAuthHttpClient $oauthHttpClient = null,
        ?\Votepit\Security\Webhook\WebhookSenderInterface $webhookSender = null,
        ?\Closure $webhookDeferRunner = null,
        ?\Votepit\Translation\TranslationHttpClient $translationHttpClient = null,
        ?HibpHttpClient $hibpHttpClient = null,
        ?ErrorReporter $errorReporter = null,
        ?Config $config = null,
        ?TurnstileHttpClient $turnstileHttpClient = null,
        ?\Votepit\Support\Clock $clock = null,
        ?\Votepit\IssueTracker\IssueTrackerHttpClient $issueTrackerHttpClient = null,
    ): App {
        $audit = new AuditLogger($this->logFile);
        // Default to InMemoryMailer — no real SMTP delivery in tests.
        $resolvedMailer = $mailer ?? new InMemoryMailer();
        return AppFactory::create(
            $config ?? $this->testConfig(),
            $this->conn,
            $resolvedMailer,
            $audit,
            avatarDirOverride: $this->avatarDir,
            boardAssetDirOverride: $this->boardAssetDir,
            // Synthetic tiers so the gating mechanism itself stays covered in
            // core even though production self-host runs unrestricted.
            planPolicy: $planPolicy ?? self::syntheticPlanPolicy(),
            extensions: $extensions ?? [],
            // OAuth login: null = the real CurlOAuthHttpClient — tests that
            // exercise GET /login/oauth/{provider}/callback's happy/error
            // paths inject an InMemoryOAuthHttpClient instead (see
            // OAuthLoginActionTest).
            oauthHttpClient: $oauthHttpClient,
            // board-webhooks: tests inject a fake sender + a synchronous
            // defer runner to exercise the full HTTP route deterministically
            // without real network access or waiting on process shutdown.
            webhookSender: $webhookSender,
            webhookDeferRunner: $webhookDeferRunner,
            // idea-comment-translation: tests exercising
            // GoogleTranslateProvider through the HTTP seam inject an
            // InMemoryTranslationHttpClient here (see
            // IdeaTranslateActionTest/CommentTranslateActionTest) —
            // otherwise unused unless testConfig() also enables translation.
            translationHttpClient: $translationHttpClient,
            // password-policy-hardening: unlike $oauthHttpClient/
            // $translationHttpClient (real client by default, only a few
            // opt-in tests exercise those paths), almost every test that
            // sets a password goes through this seam — default to an
            // unstubbed InMemoryHibpHttpClient (fail-open: "not pwned")
            // so the suite never depends on real network access. Tests
            // exercising a HIBP hit/failure inject their own stubbed
            // instance (see PasswordPolicyTest / SetPasswordActionTest).
            hibpHttpClient: $hibpHttpClient ?? new InMemoryHibpHttpClient(),
            errorReporter: $errorReporter,
            // anti-automation-challenge: null = the real CurlTurnstileHttpClient
            // — tests exercising the Turnstile challenge path inject an
            // InMemoryTurnstileHttpClient instead (see IdeaCreateActionTest/
            // VoteActionTest/CommentCreateActionTest).
            turnstileHttpClient: $turnstileHttpClient,
            // Board phase windows: null = SystemClock; BoardPhaseWindowsTest
            // injects a FixedClock for exact boundary tests.
            clock: $clock,
            // Linear/GitHub issue links: never the real network in tests —
            // an unstubbed InMemoryIssueTrackerHttpClient fails every call
            // closed; IssueTracker tests inject their own stubbed instance.
            issueTrackerHttpClient: $issueTrackerHttpClient ?? new \Votepit\IssueTracker\InMemoryIssueTrackerHttpClient(),
        );
    }

    /**
     * Synthetic tier table used by the integration tests to exercise every
     * plan gate (board count, team size, Agent API, visibility, branding).
     * Tier names and numbers are test fixtures only — the Community Edition
     * ships no tiers (UnrestrictedPlanPolicy).
     *
     * - self-host: unlimited everything (matches the seeded default account)
     * - free:      1 board, 1 member, no Agent API, public boards only, no branding fields, no roadmap Gantt, no chat notifications, no widget, no issue tracker, no guest submission
     * - lite:      5 boards, 5 members, no Agent API, all visibilities, secondary_color/logo_url/intro, no roadmap Gantt, chat notifications and widget on, no issue tracker, guest submission on
     * - pro:       unlimited, Agent API on, all visibilities, all branding fields, roadmap Gantt on, chat notifications and widget on, issue tracker on, guest submission on
     */
    public static function syntheticPlanPolicy(): TablePlanPolicy
    {
        return new TablePlanPolicy([
            'self-host' => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => PlanPolicy::ALL_BRANDING_FIELDS, 'roadmap_gantt' => true, 'chat_notifications' => true, 'widget' => true, 'issue_tracker' => true, 'anonymous_submission' => true, 'features' => ['translation']],
            'free'   => ['board_limit' => 1, 'member_limit' => 1, 'agent_api' => false, 'visibilities' => ['public'], 'branding_fields' => [], 'roadmap_gantt' => false],
            'lite'      => ['board_limit' => 5, 'member_limit' => 5, 'agent_api' => false, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => ['secondary_color', 'logo_url', 'intro', 'cover_pattern', 'accent_color', 'primary_color_dark', 'accent_color_dark', 'logo_plate'], 'roadmap_gantt' => false, 'chat_notifications' => true, 'widget' => true, 'anonymous_submission' => true, 'features' => ['translation']],
            'pro'  => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => PlanPolicy::ALL_BRANDING_FIELDS, 'roadmap_gantt' => true, 'chat_notifications' => true, 'widget' => true, 'issue_tracker' => true, 'anonymous_submission' => true, 'features' => ['translation']],
        ], 'free', 'pro');
    }

    protected function testConfig(): Config
    {
        return Config::fromArray($this->testConfigArray());
    }

    /**
     * @param  array<string, mixed> $overrides merged on top of the base test config
     * @return array<string, mixed>
     */
    protected function testConfigArray(array $overrides = []): array
    {
        return [
            'env'                  => 'dev',
            'app_url'              => 'http://localhost:8000',
            'app_key'              => str_repeat('a', 64),
            'identity_server_key'  => self::identityServerKey(),
            'db'                   => ['name' => ':memory:'],
            'smtp'                 => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'       => 900,
            ...$overrides,
        ];
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
        $conn = DriverManager::getConnection($this->connectionParams(), $this->connectionConfiguration());

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

    /**
     * Overridable hook: a subclass may return a DBAL Configuration (e.g. with
     * a driver middleware such as QueryCountingMiddleware for the query-budget
     * tests). Called from setUp() before the schema is applied.
     */
    protected function connectionConfiguration(): ?Configuration
    {
        return null;
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
                -- Legal-operator identity (migrations/0058_add_legal_operator_identity.sql):
                -- required before a board may go public-facing, see
                -- BoardCreateAction/BoardBrandingAction.
                legal_operator_name    VARCHAR(255) NULL,
                legal_operator_address TEXT NULL,
                -- International legal-notice completeness (migrations/
                -- 0064_add_legal_operator_contact.sql): the contact email
                -- rounds out the "complete legal-operator identity" gate
                -- (AccountRepository::hasCompleteLegalOperatorIdentity()),
                -- notice stays optional/supplementary.
                legal_operator_email   VARCHAR(255) NULL,
                legal_operator_notice  TEXT NULL,
                deletion_scheduled_at DATETIME NULL,
                -- Upgrade/downgrade/cancellation lifecycle: guards
                -- the export-reminder mail against resending on every login —
                -- see migrations/0016_add_board_freeze_and_deletion_reminder.sql.
                deletion_reminder_sent_at DATETIME NULL,
                -- board-operator-compliance follow-up: guards the compliance-
                -- freeze reminder mail against resending on every login — see
                -- migrations/0063_add_boards_compliance_freeze.sql.
                compliance_reminder_sent_at DATETIME NULL,
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
                suspended_at DATETIME NULL,
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
            // Same fixture-convenience backfill as confirmed_at/
            // onboarding_completed_at above: the seeded default account
            // already has a COMPLETE legal-operator identity (name+address+
            // email, migrations/0064_add_legal_operator_contact.sql) so the
            // many pre-existing public/unlisted-board fixtures keep working
            // unmodified. Tests for the legal-operator gate itself
            // (AccountLegalOperatorGateTest) override these back to null
            // explicitly.
            'legal_operator_name'    => 'Default Account',
            'legal_operator_address' => 'Musterstr. 1, 12345 Berlin',
            'legal_operator_email'   => 'operator@example.com',
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
                -- visibility when the plan forbids the board visibility
                -- (lifted again on upgrade), migrations/0118.
                frozen_reason      VARCHAR(16) NULL,
                -- board-operator-compliance follow-up: hides the board from
                -- non-members entirely (unlike frozen_at above) when the
                -- legal-operator name or acknowledgment is missing — see
                -- migrations/0063_add_boards_compliance_freeze.sql.
                compliance_frozen_at DATETIME NULL,
                slug               VARCHAR(64) NOT NULL,
                name               VARCHAR(128) NOT NULL,
                accent_color       VARCHAR(16) NOT NULL DEFAULT \'#3b82f6\',
                primary_color      VARCHAR(7) NULL,
                secondary_color    VARCHAR(7) NULL,
                logo_url           VARCHAR(512) NULL,
                -- Accent, per-mode colours and logo plate (migrations/0116_add_board_brand_accent_and_logo_plate.sql).
                brand_accent_color VARCHAR(7) NULL,
                brand_primary_color_dark VARCHAR(7) NULL,
                brand_accent_color_dark VARCHAR(7) NULL,
                brand_logo_plate   VARCHAR(16) NULL,
                brand_logo_ring_hidden INTEGER NOT NULL DEFAULT 0,
                -- Selectable cover pattern (migrations/0097_add_board_cover_pattern.sql).
                cover_pattern      VARCHAR(16) NULL,
                -- Display mode (migrations/0102_add_board_theme_mode.sql).
                theme_mode         VARCHAR(8) NULL,
                intro              TEXT NULL,
                -- Branding tiers: Pro-only "Powered by Votepit"
                -- badge-hide switch — see migrations/0014_add_boards_hide_badge.sql.
                hide_badge         INTEGER NOT NULL DEFAULT 0,
                -- per-board translation opt-in — see migrations/
                -- 0055_add_boards_translation_enabled.sql.
                translation_enabled INTEGER NOT NULL DEFAULT 0,
                -- anonymous voting opt-in (ADR 0004) — see
                -- migrations/0068_add_anonymous_voting.sql.
                anonymous_voting_enabled INTEGER NOT NULL DEFAULT 0,
                -- P-03 down-vote reasons opt-in — see migrations/0099_add_vote_reasons.sql.
                vote_reasons_enabled INTEGER NOT NULL DEFAULT 0,
                -- status decision reason required — see migrations/0115_add_boards_status_reason_required.sql.
                status_reason_required INTEGER NOT NULL DEFAULT 0,
                -- "has board_statuses rows" flag — see
                -- migrations/0073_create_board_statuses.sql.
                statuses_customized INTEGER NOT NULL DEFAULT 0,
                -- optional submission/voting windows (UTC) — see
                -- migrations/0082_add_board_phase_windows.sql.
                submissions_opens_at  DATETIME NULL,
                submissions_closes_at DATETIME NULL,
                voting_opens_at       DATETIME NULL,
                voting_closes_at      DATETIME NULL,
                -- per-board approval queue (migrations/0083_add_idea_
                -- premoderation.sql): off (default) or all.
                premoderation      VARCHAR(16) NOT NULL DEFAULT \'off\',
                -- migrations/0084_add_anonymous_idea_submission.sql
                anonymous_submission_enabled INTEGER NOT NULL DEFAULT 0,
                -- Pro branding (migrations/0093_add_board_pro_branding.sql).
                typography_preset  VARCHAR(16) NULL,
                shape_style        VARCHAR(16) NULL,
                density            VARCHAR(16) NULL,
                wording_json       TEXT NULL,
                header_links_json  TEXT NULL,
                roadmap_hidden     INTEGER NOT NULL DEFAULT 0,
                moderation_enabled INTEGER NOT NULL DEFAULT 1,
                -- back link + plaintext footer note (P-31) — see
                -- migrations/0092_add_board_assets.sql.
                back_link_url      VARCHAR(2048) NULL,
                back_link_label    VARCHAR(40) NULL,
                footer_note        VARCHAR(280) NULL,
                is_default         INTEGER NOT NULL DEFAULT 0,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (account_id, slug),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
            )',
        );

        // Board imagery (P-28) — see migrations/0092_add_board_assets.sql, ink_luminance: 0100.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_assets (
                board_id     INTEGER NOT NULL,
                variant      VARCHAR(16) NOT NULL,
                public_id    VARCHAR(32) NOT NULL UNIQUE,
                file_name    VARCHAR(40) NOT NULL,
                content_type VARCHAR(16) NOT NULL,
                width        INTEGER NOT NULL,
                height       INTEGER NOT NULL,
                ink_luminance REAL NULL,
                created_at   DATETIME NOT NULL,
                PRIMARY KEY (board_id, variant),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

        // P-03 down-vote reasons — see migrations/0099_add_vote_reasons.sql.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS vote_reasons (
                vote_id    INTEGER NOT NULL PRIMARY KEY,
                reason     VARCHAR(20) NOT NULL,
                note       VARCHAR(200) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
            )',
        );

        // Upload clause consent (AGB 19) — see migrations/0098_add_upload_terms_consents.sql.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS upload_terms_consents (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id        BIGINT UNSIGNED NULL,
                board_id          BIGINT UNSIGNED NULL,
                user_id           BIGINT UNSIGNED NULL,
                terms_version     VARCHAR(32)     NOT NULL,
                ui_language       VARCHAR(8)      NULL,
                accepted_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                account_pseudonym CHAR(64)        NULL,
                board_pseudonym   CHAR(64)        NULL,
                pseudonymised_at  DATETIME        NULL,
                UNIQUE (account_id, terms_version),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE SET NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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
                -- migrations/0074_add_user_self_service_deletion.sql
                is_deleted_placeholder INTEGER NOT NULL DEFAULT 0,
                -- migrations/0084_add_anonymous_idea_submission.sql
                is_guest_placeholder INTEGER NOT NULL DEFAULT 0,
                is_blocked    INTEGER NOT NULL DEFAULT 0,
                token_version INTEGER NOT NULL DEFAULT 0,
                -- Password + TOTP 2FA: all NULL by default (opt-in,
                -- see migrations/0018_add_password_and_totp.sql).
                password_hash          VARCHAR(255) NULL,
                totp_secret_encrypted  VARCHAR(255) NULL,
                totp_enabled_at        DATETIME NULL,
                -- TOTP replay protection (migrations/0066_add_totp_replay_
                -- protection_and_backup_code_hashing.sql): last accepted
                -- HOTP counter step, NULL = none yet.
                totp_last_step         INTEGER NULL,
                -- profile-avatar-social (migrations/0019_add_user_avatar_and_social_links.sql):
                -- opaque server-generated filename, NULL = no avatar set.
                avatar_filename VARCHAR(40) NULL,
                -- migrations/0112_add_marketing_consent.sql: § 7 Abs. 3 UWG notice
                -- timestamp and the plaintext marketing delivery address.
                existing_customer_notice_at DATETIME NULL,
                marketing_email VARCHAR(254) NULL,
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
                -- weekly owner digest (migrations/0075_add_weekly_owner_
                -- digest.sql): NULL = default (on for owned accounts).
                notify_weekly_digest_email  INTEGER NULL DEFAULT NULL,
                digest_language             VARCHAR(2) NULL,
                digest_processed_at         DATETIME NULL,
                last_seen_at                DATETIME NULL,
                -- operator nudges (migrations/0111_add_operator_nudge_preferences.sql): default on.
                notify_onboarding_nudge_email INTEGER NOT NULL DEFAULT 1,
                notify_quiet_board_email      INTEGER NOT NULL DEFAULT 1,
                notify_plan_limit_email       INTEGER NOT NULL DEFAULT 1,
                notify_update_available_email INTEGER NOT NULL DEFAULT 1,
                verified_at   DATETIME NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deletion_scheduled_at DATETIME NULL,
                UNIQUE (email_hmac),
                UNIQUE (username_lower),
                UNIQUE (public_id)
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS totp_backup_codes (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                code_hash  VARCHAR(255) NOT NULL,
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
                confirms_product_news INTEGER NOT NULL DEFAULT 0,
                signup_marketing_email VARCHAR(254) NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        // migrations/0110_create_operator_nudge_log.sql — sent-log of the operator nudges.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS operator_nudge_log (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                kind       VARCHAR(32) NOT NULL,
                scope      VARCHAR(64) NOT NULL,
                sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, account_id, kind, scope),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
            )',
        );

        // OAuth login (migrations/0052_add_oauth_login.sql): server-side
        // state (+ optional PKCE code_verifier, + return_to) and the
        // provider-identity link table. See OAuthStateRepository/
        // OAuthIdentityRepository class docs.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS oauth_states (
                id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                state_hash    CHAR(64) NOT NULL,
                provider      VARCHAR(32) NOT NULL,
                code_verifier VARCHAR(128) NULL,
                return_to     VARCHAR(500) NULL,
                expires_at    DATETIME NOT NULL,
                used_at       DATETIME NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS oauth_identities (
                id                INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id           INTEGER NOT NULL,
                provider          VARCHAR(32) NOT NULL,
                provider_user_id  VARCHAR(191) NOT NULL,
                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE (provider, provider_user_id)
            )',
        );

        // Participant mails (migrations 0104-0109, close-the-loop re-engagement).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS idea_follows (
                idea_id    INTEGER NOT NULL REFERENCES ideas(id) ON DELETE CASCADE,
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                board_id   INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (idea_id, user_id)
            )',
        );
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS participant_mail_preferences (
                user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                category VARCHAR(16) NOT NULL,
                cadence  VARCHAR(16) NOT NULL,
                PRIMARY KEY (user_id, category)
            )',
        );
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS participant_mail_queue (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient_user_id    INTEGER NULL,
                recipient_key        CHAR(64) NULL,
                recipient_email_enc  TEXT NULL,
                category             VARCHAR(16) NOT NULL,
                board_id             INTEGER NOT NULL,
                idea_id              INTEGER NOT NULL,
                event_kind           VARCHAR(16) NOT NULL,
                payload              TEXT NOT NULL,
                link_path            VARCHAR(512) NOT NULL,
                created_at           DATETIME NOT NULL,
                handled_at           DATETIME NULL,
                outcome              VARCHAR(16) NULL
            )',
        );
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS participant_digest_sends (
                recipient_key VARCHAR(80) NOT NULL,
                period_key    VARCHAR(40) NOT NULL,
                sent_at       DATETIME NOT NULL,
                PRIMARY KEY (recipient_key, period_key)
            )',
        );
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS participant_guest_suppressions (
                recipient_key CHAR(64) NOT NULL PRIMARY KEY,
                created_at    DATETIME NOT NULL
            )',
        );
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS participant_new_ideas_state (
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                board_id   INTEGER NOT NULL REFERENCES boards(id) ON DELETE CASCADE,
                checked_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, board_id)
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

        // migrations/0112_add_marketing_consent.sql — proof-carrying marketing consent.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS marketing_consents (
                user_id            INTEGER NOT NULL PRIMARY KEY,
                status             VARCHAR(16) NOT NULL,
                source             VARCHAR(16) NOT NULL,
                text_version       VARCHAR(32) NOT NULL,
                confirm_token_hash CHAR(64) NULL UNIQUE,
                confirm_expires_at DATETIME NULL,
                requested_at       DATETIME NULL,
                confirmed_at       DATETIME NULL,
                withdrawn_at       DATETIME NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CHECK (status IN (\'pending\', \'confirmed\', \'withdrawn\')),
                CHECK (source IN (\'signup\', \'profile\')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        // worklist_dismissals (migrations/0113_create_worklist_dismissals.sql, P-06 part B).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS worklist_dismissals (
                account_id          INTEGER NOT NULL,
                idea_id             INTEGER NOT NULL,
                dismissed_at        DATETIME NOT NULL,
                status_at_dismissal VARCHAR(16) NOT NULL,
                PRIMARY KEY (account_id, idea_id),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (idea_id)    REFERENCES ideas(id)    ON DELETE CASCADE
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

        // migrations/0065_add_cron_manager_runs.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS cron_manager_runs (
                job_name           VARCHAR(64) NOT NULL,
                last_dispatched_at DATETIME    NOT NULL,
                PRIMARY KEY (job_name)
            )',
        );

        // migrations/0090_add_cron_jobs.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS cron_jobs (
                job_name             VARCHAR(64)  NOT NULL,
                source               VARCHAR(32)  NOT NULL,
                cron_expr            VARCHAR(64)  NOT NULL,
                catch_up             VARCHAR(8)   NOT NULL DEFAULT \'none\',
                max_runtime_s        INTEGER      NOT NULL,
                last_slot            DATETIME     NULL,
                lease_token          CHAR(32)     NULL,
                lease_expires_at     DATETIME     NULL,
                pid                  INTEGER      NULL,
                started_at           DATETIME     NULL,
                finished_at          DATETIME     NULL,
                last_exit            INTEGER      NULL,
                consecutive_failures INTEGER      NOT NULL DEFAULT 0,
                alert_state          VARCHAR(16)  NOT NULL DEFAULT \'ok\',
                last_alerted_at      DATETIME     NULL,
                PRIMARY KEY (job_name)
            )',
        );

        // migrations/0067_add_circuit_breakers.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS circuit_breakers (
                service              VARCHAR(64) NOT NULL,
                consecutive_failures INTEGER     NOT NULL DEFAULT 0,
                opened_at            DATETIME    NULL,
                updated_at           DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (service)
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
                -- guest author pseudonym (migrations/0084_add_anonymous_idea_submission.sql)
                anon_author_token_hash CHAR(64) NULL,
                title            VARCHAR(200) NOT NULL,
                title_normalized VARCHAR(200) NOT NULL DEFAULT \'\',
                body             TEXT NOT NULL,
                status           VARCHAR(16) NOT NULL DEFAULT \'open\',
                -- pre-moderation (migrations/0083_add_idea_premoderation.sql):
                -- published or pending; pending ideas are invisible on
                -- every default read path.
                moderation_state VARCHAR(10) NOT NULL DEFAULT \'published\',
                approved_at      DATETIME NULL,
                approved_by      INTEGER NULL,
                start_date       DATE NULL,
                target_date      DATE NULL,
                is_pinned        INTEGER NOT NULL DEFAULT 0,
                merged_into_id   INTEGER NULL,
                -- merge metadata (migrations/0076_add_idea_merge_columns.sql).
                merged_at        DATETIME NULL,
                merged_by        INTEGER NULL,
                score_cache      INTEGER NOT NULL DEFAULT 0,
                view_count       INTEGER NOT NULL DEFAULT 0,
                -- denormalized counters (migrations/0072_add_idea_counters.sql),
                -- maintained app-side like score_cache.
                up_count         INTEGER NOT NULL DEFAULT 0,
                down_count       INTEGER NOT NULL DEFAULT 0,
                comment_count    INTEGER NOT NULL DEFAULT 0,
                -- board import (migrations/0077_add_idea_imports.sql):
                -- vote-count baseline from the source tool + provenance link.
                imported_vote_count INTEGER NOT NULL DEFAULT 0,
                import_id        INTEGER NULL,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                -- soft-delete (migrations/0060_add_idea_moderation_columns.sql,
                -- board-operator-compliance issue 07): same shape as
                -- comments.deleted_at/moderation_action_id below — hides a
                -- moderated idea from every public read path while keeping
                -- the row, and links back to the reason/snapshot row.
                -- SQLite does not verify a FK target exists at CREATE TABLE
                -- time (only on insert, and only with the pragma on), so
                -- referencing content_moderation_actions here, before it is
                -- created below, is safe.
                deleted_at       DATETIME NULL,
                moderation_action_id INTEGER NULL,
                FOREIGN KEY (board_id)  REFERENCES boards(id)  ON DELETE CASCADE,
                FOREIGN KEY (author_id) REFERENCES users(id)   ON DELETE RESTRICT,
                FOREIGN KEY (moderation_action_id) REFERENCES content_moderation_actions(id) ON DELETE SET NULL,
                FOREIGN KEY (merged_into_id) REFERENCES ideas(id) ON DELETE SET NULL,
                FOREIGN KEY (merged_by) REFERENCES users(id) ON DELETE SET NULL
            )',
        );

        // content_moderation_actions (migrations/0059_create_content_
        // moderation_actions.sql, board-operator-compliance issue 06):
        // shared reason-coded moderation record for comments AND ideas
        // (entity_type/entity_id is a polymorphic reference, no FK — see
        // the migration's doc comment). Must exist before `comments`
        // below, which FKs moderation_action_id into it.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS content_moderation_actions (
                id               INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                entity_type      VARCHAR(16) NOT NULL,
                entity_id        INTEGER NOT NULL,
                board_id         INTEGER NOT NULL,
                account_id       INTEGER NOT NULL,
                actor_user_id    INTEGER NOT NULL,
                actor_tier       VARCHAR(16) NOT NULL,
                reason_code      VARCHAR(32) NOT NULL,
                reason_note      TEXT NULL,
                content_snapshot TEXT NOT NULL,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                purge_after      DATETIME NOT NULL,
                CHECK (entity_type IN (\'idea\', \'comment\')),
                CHECK (actor_tier IN (\'board_moderator\', \'operator\')),
                CHECK (reason_code IN (
                    \'community_rule\', \'illegal_copyright\', \'illegal_privacy\', \'illegal_threat\',
                    \'illegal_fraud\', \'illegal_other\', \'other\'
                )),
                FOREIGN KEY (board_id)      REFERENCES boards(id)   ON DELETE CASCADE,
                FOREIGN KEY (account_id)    REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (actor_user_id) REFERENCES users(id)    ON DELETE RESTRICT
            )',
        );

        // Read path: votes + comments. Currently only display aggregates
        // (score/consensus/comment count); the mutation endpoints follow
        // later. Portable subset (without UNSIGNED/ENGINE).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS votes (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                idea_id    INTEGER NOT NULL,
                -- anonymous voting (ADR 0004): user_id is nullable, an
                -- anonymous vote row carries voter_token_hash instead —
                -- see migrations/0068_add_anonymous_voting.sql.
                user_id    INTEGER NULL,
                voter_token_hash VARCHAR(64) NULL,
                voter_email_enc TEXT NULL,
                value      INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (idea_id, user_id),
                UNIQUE (idea_id, voter_token_hash),
                FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CHECK (
                    (user_id IS NOT NULL AND voter_token_hash IS NULL) OR
                    (user_id IS NULL AND voter_token_hash IS NOT NULL)
                )
            )',
        );

        // Double-vote-prevention follow-up (ADR 0004) — see
        // migrations/0070_add_voter_identity_links.sql.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS voter_identity_links (
                voter_token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
                user_id          INTEGER NOT NULL,
                linked_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                -- soft-delete (migrations/0059_create_content_moderation_
                -- actions.sql, board-operator-compliance issue 06):
                -- deleted_at hides a moderated comment from every public
                -- read path while keeping the row; moderation_action_id
                -- links back to the reason/snapshot row.
                deleted_at DATETIME NULL,
                moderation_action_id INTEGER NULL,
                FOREIGN KEY (idea_id)   REFERENCES ideas(id)  ON DELETE CASCADE,
                FOREIGN KEY (author_id) REFERENCES users(id)  ON DELETE CASCADE,
                FOREIGN KEY (moderation_action_id) REFERENCES content_moderation_actions(id) ON DELETE SET NULL
            )',
        );

        // content_moderation_appeals (migrations/0061_create_content_
        // moderation_appeals.sql, board-operator-compliance issue 09): one
        // row per appeal attempt against a content_moderation_actions row.
        // "One open appeal per moderation action" is an app-level check
        // (ModerationAppealRepository::hasPendingAppeal()), not a schema
        // constraint — see the migration's doc comment.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS content_moderation_appeals (
                id                   INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                moderation_action_id INTEGER NOT NULL,
                submitted_by_user_id INTEGER NOT NULL,
                message              TEXT NOT NULL,
                status               VARCHAR(16) NOT NULL DEFAULT \'pending\',
                resolved_by_user_id  INTEGER NULL,
                resolved_at          DATETIME NULL,
                created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CHECK (status IN (\'pending\', \'upheld\', \'overturned\')),
                FOREIGN KEY (moderation_action_id) REFERENCES content_moderation_actions(id) ON DELETE CASCADE,
                FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
        );

        // account_member_standing (migrations/0062_create_account_member_
        // standing.sql, board-operator-compliance issue 10): repeat-offender
        // escalation, one row per (account, member). state_changed_by_user_id
        // IS NULL for an automatic change (Domain\ModerationStandingService),
        // set for a manual override — see the migration's doc comment.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS account_member_standing (
                account_id               INTEGER NOT NULL,
                user_id                  INTEGER NOT NULL,
                violation_count          INTEGER NOT NULL DEFAULT 0,
                state                    VARCHAR(16) NOT NULL DEFAULT \'normal\',
                state_changed_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                state_changed_by_user_id INTEGER NULL,
                trusted_at               DATETIME NULL,
                trusted_by_user_id       INTEGER NULL,
                PRIMARY KEY (account_id, user_id),
                CHECK (state IN (\'normal\', \'flagged\', \'warned\', \'suspended\')),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (trusted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
        );

        // migrations/0050_add_comment_reactions.sql: one active reaction per
        // (comment, user), same UNIQUE-backstop toggle pattern as votes.
        // SQLite has no CHECK-constraint parity concern here (the value set
        // is enforced app-side, CommentReactionRepository::isAllowed()).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS comment_reactions (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                comment_id INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                reaction   VARCHAR(8) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (comment_id, user_id),
                FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
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
                oauth_client_id    INTEGER NULL,
                token_hash         CHAR(64) NOT NULL,
                last_used_at       DATETIME NULL,
                revoked_at         DATETIME NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (token_hash),
                FOREIGN KEY (account_id)         REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT,
                FOREIGN KEY (oauth_client_id)    REFERENCES oauth_clients(id) ON DELETE CASCADE
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

        // MCP OAuth 2.1 (migrations/0088_add_mcp_oauth.sql): registered
        // public clients, single-use authorization codes and the access/
        // refresh tokens of a grant (an api_tokens row with oauth_client_id).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS oauth_clients (
                id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                client_id     VARCHAR(64) NOT NULL,
                client_name   VARCHAR(100) NOT NULL,
                redirect_uris TEXT NOT NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (client_id)
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
                id              INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                code_hash       CHAR(64) NOT NULL,
                oauth_client_id INTEGER NOT NULL,
                account_id      INTEGER NOT NULL,
                user_id         INTEGER NOT NULL,
                board_scopes    TEXT NOT NULL,
                redirect_uri    VARCHAR(512) NOT NULL,
                code_challenge  CHAR(43) NOT NULL,
                expires_at      DATETIME NOT NULL,
                consumed_at     DATETIME NULL,
                api_token_id    INTEGER NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (code_hash),
                FOREIGN KEY (oauth_client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE,
                FOREIGN KEY (account_id)      REFERENCES accounts(id)      ON DELETE CASCADE,
                FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
                FOREIGN KEY (api_token_id)    REFERENCES api_tokens(id)    ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS oauth_tokens (
                id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                api_token_id INTEGER NOT NULL,
                kind         VARCHAR(10) NOT NULL CHECK (kind IN (\'access\', \'refresh\')),
                token_hash   CHAR(64) NOT NULL,
                expires_at   DATETIME NOT NULL,
                rotated_at   DATETIME NULL,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (token_hash),
                FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
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

        // board_webhooks / board_webhook_deliveries — see
        // migrations/0050_add_board_webhooks.sql for the full rationale
        // (one webhook per board, secret encrypted at rest).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_webhooks (
                id               INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id         INTEGER NOT NULL,
                url              VARCHAR(2048) NOT NULL,
                secret_encrypted VARCHAR(512) NOT NULL,
                active           INTEGER NOT NULL DEFAULT 1,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_webhook_deliveries (
                id               INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_webhook_id INTEGER NOT NULL,
                event            VARCHAR(64) NOT NULL,
                success          INTEGER NOT NULL,
                status_code      INTEGER NULL,
                error            VARCHAR(255) NULL,
                attempted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (board_webhook_id) REFERENCES board_webhooks(id) ON DELETE CASCADE
            )',
        );

        // board_chat_integrations — Slack/Discord chat notifications, see
        // migrations/0079_add_board_chat_integrations.sql.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_chat_integrations (
                id                  INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id            INTEGER NOT NULL,
                provider            VARCHAR(16) NOT NULL,
                url_encrypted       VARCHAR(1024) NOT NULL,
                url_hint            VARCHAR(96) NOT NULL,
                last_delivery_at    DATETIME NULL,
                last_delivery_ok    INTEGER NULL,
                last_delivery_error VARCHAR(64) NULL,
                created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id, provider),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

        // account_issue_trackers + idea_issue_links — Linear/GitHub issue
        // links, see migrations/0080_add_issue_tracker_links.sql.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS account_issue_trackers (
                id                       INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id               INTEGER NOT NULL,
                provider                 VARCHAR(16) NOT NULL,
                token_encrypted          VARCHAR(1024) NOT NULL,
                token_hint               VARCHAR(32) NOT NULL,
                target                   VARCHAR(200) NOT NULL,
                target_id                VARCHAR(64) NOT NULL DEFAULT \'\',
                webhook_id               CHAR(32) NOT NULL,
                webhook_secret_encrypted VARCHAR(512) NULL,
                created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (account_id, provider),
                UNIQUE (webhook_id),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
            )',
        );
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS idea_issue_links (
                id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                account_id   INTEGER NOT NULL,
                idea_id      INTEGER NOT NULL,
                provider     VARCHAR(16) NOT NULL,
                external_id  VARCHAR(64) NOT NULL,
                external_key VARCHAR(64) NOT NULL,
                url          VARCHAR(512) NOT NULL,
                created_by   INTEGER NULL,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (idea_id, provider),
                UNIQUE (account_id, provider, external_id),
                FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )',
        );

        // board_widgets — embeddable widget config, see
        // migrations/0078_add_board_widgets.sql (ADR 0005).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_widgets (
                id                INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id          INTEGER NOT NULL,
                allowed_origins   TEXT NOT NULL,
                secret_encrypted  VARCHAR(512) NULL,
                secret_rotated_at DATETIME NULL,
                last_seen_origin  VARCHAR(255) NULL,
                last_seen_at      DATETIME NULL,
                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

        // migrations/0073_create_board_statuses.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_statuses (
                id             INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id       INTEGER NOT NULL,
                status_key     VARCHAR(16) NOT NULL,
                category       VARCHAR(16) NOT NULL,
                label_de       VARCHAR(40) NULL,
                label_en       VARCHAR(40) NULL,
                position       INTEGER NOT NULL DEFAULT 0,
                is_hidden      INTEGER NOT NULL DEFAULT 0,
                allow_votes    INTEGER NOT NULL DEFAULT 1,
                allow_comments INTEGER NOT NULL DEFAULT 1,
                is_system      INTEGER NOT NULL DEFAULT 0,
                UNIQUE (board_id, status_key),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

        // migrations/0091_create_idea_status_decisions.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS idea_status_decisions (
                id               INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id         INTEGER NOT NULL,
                idea_id          INTEGER NOT NULL,
                status_from      VARCHAR(16) NOT NULL,
                status_to        VARCHAR(16) NOT NULL,
                reason           VARCHAR(1000) NULL,
                next_step        VARCHAR(280) NULL,
                responsible_role VARCHAR(16) NULL CHECK (responsible_role IS NULL OR responsible_role IN (\'team\', \'moderation\')),
                actor_user_id    INTEGER NULL,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
                FOREIGN KEY (idea_id)  REFERENCES ideas(id)  ON DELETE CASCADE,
                FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
        );

        // migrations/0051_create_idea_tags.sql
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS idea_tags (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id   INTEGER NOT NULL,
                name       VARCHAR(40) NOT NULL,
                color      VARCHAR(7) NOT NULL DEFAULT \'#3b82f6\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id, name),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

        // migrations/0077_add_idea_imports.sql — one row per applied board
        // import, UNIQUE(board_id, idempotency_key) is the replay backstop.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS idea_imports (
                id              INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id        INTEGER NOT NULL,
                actor_user_id   INTEGER NULL,
                idempotency_key VARCHAR(64) NOT NULL,
                format          VARCHAR(16) NOT NULL,
                content_sha256  CHAR(64) NOT NULL,
                imported_count  INTEGER NOT NULL DEFAULT 0,
                skipped_count   INTEGER NOT NULL DEFAULT 0,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id, idempotency_key),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
                FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
        );

        // content_translations (idea-comment-translation feature, see
        // migrations/0054_create_content_translations.sql) — polymorphic
        // cache table, no FK on entity_id (references ideas OR comments
        // depending on entity_type), same portability trade-off as
        // abuse_reports above.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS content_translations (
                id                      INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                entity_type             VARCHAR(16) NOT NULL,
                entity_id               INTEGER NOT NULL,
                target_locale           VARCHAR(10) NOT NULL,
                source_hash             CHAR(64) NOT NULL,
                detected_source_locale  VARCHAR(10) NOT NULL,
                translated_title        VARCHAR(500) NULL,
                translated_body         TEXT NOT NULL,
                provider                VARCHAR(32) NOT NULL,
                created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, target_locale)
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS idea_tag_assignments (
                idea_id    INTEGER NOT NULL,
                tag_id     INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (idea_id, tag_id),
                FOREIGN KEY (idea_id) REFERENCES ideas(id)     ON DELETE CASCADE,
                FOREIGN KEY (tag_id)  REFERENCES idea_tags(id) ON DELETE CASCADE
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
            // Same convenience default as the seeded default account (see
            // applySchema()) — most tests don't care about the
            // legal-operator gate; pass ['legal_operator_name' => null] in
            // $overrides to exercise it.
            'legal_operator_name'    => 'Test Account',
            'legal_operator_address' => 'Musterstr. 1, 12345 Berlin',
            'legal_operator_email'   => 'operator@example.com',
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
     * seedIdea() override where needed. Does bump the denormalized
     * up_count/down_count (migration 0072), so read paths see the same
     * figures the replaced COUNT subqueries returned. Returns the vote ID.
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
        $voteId = (int) $this->conn->lastInsertId();

        $value  = (int) ($overrides['value'] ?? $value);
        $ideaId = (int) ($overrides['idea_id'] ?? $ideaId);
        if ($value !== 0) {
            $column = $value > 0 ? 'up_count' : 'down_count';
            $this->conn->executeStatement("UPDATE ideas SET {$column} = {$column} + 1 WHERE id = :id", ['id' => $ideaId]);
        }

        return $voteId;
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
        $commentId = (int) $this->conn->lastInsertId();

        // Denormalized comment_count (migration 0072) counts visible comments only.
        if (($overrides['deleted_at'] ?? null) === null) {
            $this->conn->executeStatement(
                'UPDATE ideas SET comment_count = comment_count + 1 WHERE id = :id',
                ['id' => (int) ($overrides['idea_id'] ?? $ideaId)],
            );
        }

        return $commentId;
    }

    /**
     * Seeds a content_moderation_actions row (board-operator-compliance
     * issue 06/08); returns its ID. Defaults produce a row already past
     * its retention window (purge_after in the past) with a non-empty
     * content_snapshot — i.e. eligible for
     * Cleanup\ModerationPurgeService::run() unless overridden — since most
     * callers of this helper are ModerationPurgeService/ModerationRepository
     * purge tests.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedModerationAction(
        string $entityType,
        int $entityId,
        int $boardId,
        int $accountId,
        int $actorUserId,
        array $overrides = [],
    ): int {
        $this->conn->insert('content_moderation_actions', array_merge([
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'board_id'         => $boardId,
            'account_id'       => $accountId,
            'actor_user_id'    => $actorUserId,
            'actor_tier'       => 'board_moderator',
            'reason_code'      => 'community_rule',
            'reason_note'      => null,
            'content_snapshot' => 'Original content, kept for appeal review.',
            'created_at'       => (new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s'),
            'purge_after'      => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a content_moderation_appeals row (board-operator-compliance
     * issue 09); returns its ID. Defaults to a fresh 'pending' appeal.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedAppeal(
        int $moderationActionId,
        int $submittedByUserId,
        string $message = 'I believe this was removed by mistake.',
        array $overrides = [],
    ): int {
        $this->conn->insert('content_moderation_appeals', array_merge([
            'moderation_action_id' => $moderationActionId,
            'submitted_by_user_id' => $submittedByUserId,
            'message'               => $message,
            'status'                => 'pending',
            'resolved_by_user_id'   => null,
            'resolved_at'           => null,
            'created_at'            => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds an account_member_standing row (board-operator-compliance
     * issue 10). Defaults to a fresh 'normal' row with violation_count 0
     * and an automatic (NULL) state_changed_by_user_id.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedStanding(
        int $accountId,
        int $userId,
        string $state = 'normal',
        int $violationCount = 0,
        array $overrides = [],
    ): void {
        $this->conn->insert('account_member_standing', array_merge([
            'account_id'               => $accountId,
            'user_id'                  => $userId,
            'violation_count'          => $violationCount,
            'state'                    => $state,
            'state_changed_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'state_changed_by_user_id' => null,
        ], $overrides));
    }

    /**
     * Seeds a comment_reactions row; returns its ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedReaction(int $commentId, int $userId, string $reaction = '👍', array $overrides = []): int
    {
        $this->conn->insert('comment_reactions', array_merge([
            'comment_id' => $commentId,
            'user_id'    => $userId,
            'reaction'   => $reaction,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seeds a board-scoped tag; returns its ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertTag(int $boardId, string $name = 'Bug', array $overrides = []): int
    {
        $this->conn->insert('idea_tags', array_merge([
            'board_id'   => $boardId,
            'name'       => $name,
            'color'      => '#3b82f6',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /** Assigns a tag to an idea (idea_tag_assignments row). */
    protected function assignTag(int $ideaId, int $tagId): void
    {
        $this->conn->insert('idea_tag_assignments', [
            'idea_id'    => $ideaId,
            'tag_id'     => $tagId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
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
