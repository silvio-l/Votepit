-- 0047_add_per_board_api_token_scope — makes API token (Agent API / Votepit
-- MCP) permissions granular per board: a single token can now grant 'read'
-- on one board and 'write' on another, instead of one coarse scope shared
-- by every board it grants (migration 0044 introduced multi-board tokens
-- but kept a single token-wide scope). Existing grants are backfilled 1:1
-- from their token's current scope — no behavior change for tokens issued
-- before this migration.
ALTER TABLE api_token_boards
    ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'write' AFTER board_id;

ALTER TABLE api_token_boards
    ADD CONSTRAINT chk_api_token_boards_scope CHECK (scope IN ('read', 'write'));

UPDATE api_token_boards atb
INNER JOIN api_tokens t ON t.id = atb.token_id
SET atb.scope = t.scope;

ALTER TABLE api_tokens
    DROP CHECK chk_api_tokens_scope;

ALTER TABLE api_tokens
    DROP COLUMN scope;
