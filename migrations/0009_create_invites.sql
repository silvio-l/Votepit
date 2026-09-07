-- 0009_create_invites — Sprint 16: Roles & invitations.
--
-- invites: account-scoped, hashed-token pending invitation. Mirrors the
-- login_tokens hashed-token/expiry pattern (Votepit\Persistence\LoginTokenRepository)
-- — same crypto (Votepit\Security\TokenVault: SHA-256 hash, plaintext only ever
-- in the mail link), NOT a second token scheme.
--
-- user_id references the (possibly just-provisioned, unverified) invited user
-- row — created eagerly at invite-send time the same way POST /login already
-- provisions an unknown email (UserRepository::findByEmailHmac() ?? create()).
-- This lets the accept flow match purely on `invites.user_id = session user id`
-- instead of re-deriving/storing any email/HMAC on the invite row itself.
--
-- used_at    = accepted (becomes account_members, one-time capability consumed).
-- revoked_at = owner cancelled a still-pending invite.
-- Both NULL   = pending. Both are mutually exclusive in practice (app-enforced).
--
-- role is currently always 'moderator' (acceptance criteria: invited members
-- never become owner via invite) — CHECK constraint documents that invariant;
-- the column exists so a future deliberate widening doesn't need new DDL.
CREATE TABLE IF NOT EXISTS invites (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id  BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    invited_by  BIGINT UNSIGNED NOT NULL,
    role        VARCHAR(16)     NOT NULL DEFAULT 'moderator',
    token_hash  CHAR(64)        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    revoked_at  DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_invites_hash (token_hash),
    KEY idx_invites_account_pending (account_id, used_at, revoked_at),
    CONSTRAINT fk_invites_account    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_invites_user       FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_invites_invited_by FOREIGN KEY (invited_by) REFERENCES users(id)    ON DELETE RESTRICT,
    CONSTRAINT chk_invites_role      CHECK (role IN ('moderator'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
