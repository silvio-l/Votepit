-- 0013_add_operator_panel — Sprint 22: Operator panel (platform super-admin).
--
-- users.is_operator: a NEW, strictly-higher authz tier ABOVE account-scoping
-- (accountAdmin()/accountOwner(), account_members.role) AND above the existing
-- installation-wide users.is_admin (which self-promotes via Config::
-- isAdminEmailHmac() on login — see LoginVerifyAction). is_operator has
-- DELIBERATELY NO self-service or HTTP-reachable promotion path anywhere in
-- this codebase (no promoteOperator() method, no admin_emails-style config
-- allowlist) — it is settable ONLY via a direct DB UPDATE by whoever operates
-- the installation:
--
--   UPDATE users SET is_operator = 1 WHERE id = <trusted-operator-user-id>;
--
-- This is intentional: a platform operator can lock/delete ANY account or
-- board regardless of ownership (AuthZMiddleware::operator()), so granting it
-- must never be reachable through the application itself.
ALTER TABLE users ADD COLUMN is_operator TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin;

-- accounts.locked_at / boards.locked_at: reversible operator kill-switch,
-- distinct from accounts.confirmed_at (the confirm-before-public
-- spam gate) and from boards.visibility (the owner/plan-controlled
-- tier feature). NULL = not locked (default). Both extend the SAME public-
-- visibility chokepoint (BoardRepository::findPublicBySlugForAccount()) that
-- confirmed_at and visibility already extend, instead of adding a third
-- parallel check.
ALTER TABLE accounts ADD COLUMN locked_at DATETIME NULL AFTER confirmed_at;
ALTER TABLE boards ADD COLUMN locked_at DATETIME NULL AFTER visibility;

-- abuse_reports: functional intake→storage→operator-review pipeline for the
-- DSA Art. 16 reporting mechanism (the legal documentation of the process
-- itself is a separate step — this is only the data model + endpoints). Reachable
-- unauthenticated (anon report submission) — account_id/board_id/idea_id are
-- therefore NULLable: submission is always accepted and stored even when the
-- reported slug/idea no longer resolves (ON DELETE SET NULL keeps the report
-- row alive as a review record even if the reported content is later
-- deleted). target_url is the raw reporter-supplied location, kept
-- unconditionally regardless of whether it resolved.
--
-- reporter_email_enc: optional reporter contact address, encrypted at rest
-- (Votepit\Security\EncryptionService, same sodium_crypto_secretbox scheme as
-- board SMTP passwords, dedicated HKDF context 'abuse_report' — see
-- AppFactory) rather than pseudonymized via the ADR-0002 email_hmac scheme:
-- unlike a platform user, a reporter is not identified/authenticated by this
-- address, and the operator may need to write back to them during DSA
-- follow-up, which an HMAC cannot support.
CREATE TABLE IF NOT EXISTS abuse_reports (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id         BIGINT UNSIGNED NULL,
    board_id           BIGINT UNSIGNED NULL,
    idea_id            BIGINT UNSIGNED NULL,
    target_url         VARCHAR(512)    NOT NULL,
    reason             TEXT            NOT NULL,
    reporter_email_enc TEXT            NULL,
    status             VARCHAR(16)     NOT NULL DEFAULT 'open',
    reviewed_by        BIGINT UNSIGNED NULL,
    reviewed_at        DATETIME        NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_abuse_reports_status (status, created_at),
    CONSTRAINT fk_abuse_reports_account     FOREIGN KEY (account_id)  REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_abuse_reports_board       FOREIGN KEY (board_id)    REFERENCES boards(id)   ON DELETE SET NULL,
    CONSTRAINT fk_abuse_reports_idea        FOREIGN KEY (idea_id)     REFERENCES ideas(id)    ON DELETE SET NULL,
    CONSTRAINT fk_abuse_reports_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT chk_abuse_reports_status     CHECK (status IN ('open', 'reviewed', 'dismissed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
