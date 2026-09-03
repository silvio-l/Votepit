-- 0010_create_api_tokens — Sprint 17 (Part 1): Agent API / Votepit MCP.
--
-- api_tokens: board-scoped bearer token for programmatic (bot/agent) access —
-- a NEW trust boundary alongside the existing session-cookie auth. Mirrors the
-- login_tokens/invites hashed-token-at-rest pattern (Votepit\Security\TokenVault:
-- SHA-256 hash, plaintext shown to the admin exactly once at creation time,
-- never stored/logged) — same crypto, not a new scheme.
--
-- Scoping is the load-bearing invariant here: a token is minted for exactly
-- ONE board (board_id NOT NULL — no board-less/account-wide token) and
-- denormalizes account_id alongside it so ApiTokenAuthMiddleware can resolve
-- both without a join on every request. created_by_user_id is the admin
-- (owner|moderator) who generated the token; ideas/comments created through
-- the token are attributed to that user (no separate "bot identity" concept
-- yet — a deliberate simplification, see Sprint 17 roadmap note for Part 2).
--
-- revoked_at: soft-revoke (never deleted — keeps an audit trail + FK targets
-- alive). last_used_at: best-effort telemetry, updated on each successful
-- ApiTokenRepository::findByHash() lookup (fail-open — a write error there
-- must never block the request it is authenticating).
CREATE TABLE IF NOT EXISTS api_tokens (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id         BIGINT UNSIGNED NOT NULL,
    board_id           BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    label              VARCHAR(100)    NOT NULL,
    token_hash         CHAR(64)        NOT NULL,
    last_used_at       DATETIME        NULL,
    revoked_at         DATETIME        NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_board (board_id, revoked_at),
    CONSTRAINT fk_api_tokens_account    FOREIGN KEY (account_id)         REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_api_tokens_board      FOREIGN KEY (board_id)           REFERENCES boards(id)   ON DELETE CASCADE,
    CONSTRAINT fk_api_tokens_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
