-- 0018_add_password_and_totp — optional password + TOTP 2FA (Authenticator app).
--
-- Additive to the existing magic-link-only auth model — magic link stays
-- always available (see CLAUDE.md scope note). Both columns are NULL by
-- default: a fresh/existing user has neither until they opt in via the
-- Profile page.
--
-- users.password_hash: PASSWORD_ARGON2ID hash (SetPasswordAction). NULL =
-- no password set, POST /login/password always fails generically for such a
-- user (LoginPasswordAction verifies against a fixed dummy hash in that case
-- to avoid a timing side-channel that would otherwise leak "has a password").
--
-- users.totp_secret_encrypted: EncryptionService ciphertext (context 'totp'
-- — deliberately NOT 'smtp', key-separation from the existing SMTP-secret
-- use of EncryptionService). NULL until TotpConfirmAction persists a
-- confirmed secret; the freshly-generated, not-yet-confirmed secret during
-- setup never touches the DB (TotpSetupToken carries it in a signed blob).
--
-- users.totp_enabled_at: NULL = TOTP off. Non-null gates BOTH the magic-link
-- verify flow (LoginVerifyAction) and the new password-login flow behind the
-- pending-2FA step (POST /login/2fa) — see LoginTokenRepository's new
-- '2fa_pending' purpose below. This is the actual security property of this
-- migration: an attacker who only compromises the mailbox can no longer log
-- in once TOTP is enabled.
ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL AFTER token_version;
ALTER TABLE users ADD COLUMN totp_secret_encrypted VARCHAR(255) NULL AFTER password_hash;
ALTER TABLE users ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret_encrypted;

-- totp_backup_codes: 10 single-use fallback codes, issued whenever TOTP is
-- confirmed or explicitly regenerated (TotpConfirmAction /
-- TotpBackupCodesRegenerateAction). Only the SHA-256 hash is stored — the
-- plaintext is returned exactly once, in the API response right after
-- generation, and never again. used_at NULL = still redeemable.
CREATE TABLE IF NOT EXISTS totp_backup_codes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    code_hash  CHAR(64)        NOT NULL,
    used_at    DATETIME        NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_totp_backup_codes_user (user_id),
    CONSTRAINT fk_totp_backup_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: no new table for the pending-2FA state — login_tokens (hash + expiry
-- + single-use, exactly the right shape) already supports an arbitrary
-- `purpose` column; the new purpose value '2fa_pending' is written by
-- LoginTokenRepository::insertPending() (short TTL, ~5 minutes) and never
-- requires a schema change here.
