-- 0066_add_totp_replay_protection_and_backup_code_hashing — OWASP/BSI audit
-- remediation issue 03 (TOTP hardening).
--
-- users.totp_last_step: the RFC 4226 HOTP counter (unix_time / 30) of the
-- last successfully accepted TOTP code for this user. A code whose counter
-- is <= this value is rejected even if it would otherwise verify — closes
-- the classic TOTP replay window (an attacker who observes/intercepts a
-- code in transit could otherwise reuse it for the rest of its ±1-step
-- validity, CWE-294). NULL = no code accepted yet. Set by
-- Totp::verifyStep() callers (Login2faAction, TotpAction::confirm/disable,
-- confirmedByPasswordOrCode()) on every successful verification.
ALTER TABLE users ADD COLUMN totp_last_step BIGINT NULL AFTER totp_enabled_at;

-- totp_backup_codes.code_hash: was CHAR(64) (a bare hash('sha256', ...) hex
-- digest). Backup codes now hash with Argon2id (TotpBackupCodes::hash(),
-- self-salting PASSWORD_ARGON2ID) whose encoded output (~96 chars with the
-- default cost params, embedding algorithm/params/salt/hash) no longer fits
-- CHAR(64) — widened to VARCHAR(255), the same width already used for
-- users.password_hash (migrations/0018_add_password_and_totp.sql), which
-- hashes with the same primitive. Existing rows keep their (shorter, still
-- valid) legacy SHA-256 hex value — TotpBackupCodes::verify() still accepts
-- that format, see its class doc; only a fresh regenerate() moves a user's
-- codes onto Argon2id.
ALTER TABLE totp_backup_codes MODIFY COLUMN code_hash VARCHAR(255) NOT NULL;
