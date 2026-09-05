-- 0044_rebuild_api_tokens_account_scoped — moves API tokens (Agent API /
-- Votepit MCP) from board-scoped to account-scoped, grantable across
-- multiple boards, with a coarse read/write scope. Existing tokens are
-- migrated 1:1 (their single board becomes their sole grant, scope
-- defaults to 'write' — unchanged rights for existing tokens).
CREATE TABLE IF NOT EXISTS api_token_boards (
    token_id BIGINT UNSIGNED NOT NULL,
    board_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (token_id, board_id),
    CONSTRAINT fk_api_token_boards_token FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE,
    CONSTRAINT fk_api_token_boards_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE api_tokens
    ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'write' AFTER label;

ALTER TABLE api_tokens
    ADD CONSTRAINT chk_api_tokens_scope CHECK (scope IN ('read', 'write'));

INSERT INTO api_token_boards (token_id, board_id)
SELECT id, board_id FROM api_tokens;

ALTER TABLE api_tokens
    DROP FOREIGN KEY fk_api_tokens_board;

ALTER TABLE api_tokens
    DROP KEY idx_api_tokens_board;

ALTER TABLE api_tokens
    DROP COLUMN board_id;

CREATE INDEX idx_api_tokens_account ON api_tokens (account_id, revoked_at);
