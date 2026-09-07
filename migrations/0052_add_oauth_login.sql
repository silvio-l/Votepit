-- 0052_add_oauth_login — OAuth2 login (Google/GitHub), additive to the
-- existing magic-link/password/TOTP-2FA login flows (all land on the same
-- LoginSessionIssuer). oauth_states: server-side, single-use, expiring
-- storage for the authorization-code `state` (+ optional PKCE
-- code_verifier, + the validated return_to path) — never trusted from a
-- client-supplied cookie. oauth_identities: links a provider's stable
-- subject id to an EXISTING users row (one email = one user,
-- UserRepository::findByEmailHmac()/create() is reused verbatim for
-- account resolution) — NEVER stores an email in any form (ADR 0002).
CREATE TABLE IF NOT EXISTS oauth_states (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    state_hash     CHAR(64)        NOT NULL,
    provider       VARCHAR(32)     NOT NULL,
    code_verifier  VARCHAR(128)    NULL,
    return_to      VARCHAR(500)    NULL,
    expires_at     DATETIME        NOT NULL,
    used_at        DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oauth_states_hash (state_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_identities (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    provider          VARCHAR(32)     NOT NULL,
    provider_user_id  VARCHAR(191)    NOT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_oauth_identity (provider, provider_user_id),
    KEY idx_oauth_identities_user (user_id),
    CONSTRAINT fk_oauth_identities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
