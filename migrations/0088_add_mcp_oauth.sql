-- 0088_add_mcp_oauth — OAuth 2.1 authorization server for the MCP endpoint
-- (competitor-parity issue 21). MCP clients (Claude, Claude Code, …) register
-- themselves (RFC 7591, public clients only), send the user through a consent
-- screen and exchange an authorization code (PKCE S256) for a short-lived
-- access token plus a rotating refresh token.
--
-- The grant itself is an ordinary api_tokens row (oauth_client_id set): the
-- consenting account owner/admin is its created_by_user_id and its board
-- grants live in api_token_boards exactly like a static token's. That keeps
-- ONE authorization model — scoping, per-token rate limits and write budgets,
-- the moderate grant's issuer-role check, revocation on the token page and the
-- plan-downgrade revocation (revokeAllForAccount) all apply unchanged. Such a
-- row's token_hash is the hash of a random secret that is never handed out,
-- and the static-token lookup additionally excludes oauth_client_id IS NOT
-- NULL, so a grant can only ever be used through its OAuth tokens.
--
-- Every secret (authorization code, access token, refresh token) is stored as
-- a SHA-256 hash only (TokenVault), never in plaintext.
CREATE TABLE IF NOT EXISTS oauth_clients (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id     VARCHAR(64)     NOT NULL,
    client_name   VARCHAR(100)    NOT NULL,
    redirect_uris TEXT            NOT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_clients_client_id (client_id),
    KEY idx_oauth_clients_created (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE api_tokens
    ADD COLUMN oauth_client_id BIGINT UNSIGNED NULL AFTER label;

ALTER TABLE api_tokens
    ADD CONSTRAINT fk_api_tokens_oauth_client FOREIGN KEY (oauth_client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE;

-- Single-use authorization codes. consumed_at + api_token_id survive the
-- exchange until the row is purged so a replayed code can revoke the grant it
-- produced (OAuth 2.1 §4.1.3).
CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code_hash       CHAR(64)        NOT NULL,
    oauth_client_id BIGINT UNSIGNED NOT NULL,
    account_id      BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    board_scopes    TEXT            NOT NULL,
    redirect_uri    VARCHAR(512)    NOT NULL,
    code_challenge  CHAR(43)        NOT NULL,
    expires_at      DATETIME        NOT NULL,
    consumed_at     DATETIME        NULL,
    api_token_id    BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_codes_hash (code_hash),
    KEY idx_oauth_codes_expires (expires_at),
    CONSTRAINT fk_oauth_codes_client  FOREIGN KEY (oauth_client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_codes_account FOREIGN KEY (account_id)      REFERENCES accounts (id)      ON DELETE CASCADE,
    CONSTRAINT fk_oauth_codes_user    FOREIGN KEY (user_id)         REFERENCES users (id)         ON DELETE CASCADE,
    CONSTRAINT fk_oauth_codes_grant   FOREIGN KEY (api_token_id)    REFERENCES api_tokens (id)    ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Access and refresh tokens of a grant (api_token_id = the refresh-token
-- family). rotated_at marks a refresh token that was already exchanged:
-- presenting it again revokes the whole grant (reuse detection).
CREATE TABLE IF NOT EXISTS oauth_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_token_id BIGINT UNSIGNED NOT NULL,
    kind         VARCHAR(10)     NOT NULL,
    token_hash   CHAR(64)        NOT NULL,
    expires_at   DATETIME        NOT NULL,
    rotated_at   DATETIME        NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_tokens_hash (token_hash),
    KEY idx_oauth_tokens_grant (api_token_id),
    KEY idx_oauth_tokens_expires (expires_at),
    CONSTRAINT chk_oauth_tokens_kind CHECK (kind IN ('access', 'refresh')),
    CONSTRAINT fk_oauth_tokens_grant FOREIGN KEY (api_token_id) REFERENCES api_tokens (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
