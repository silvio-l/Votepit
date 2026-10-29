-- 0081_add_api_token_moderate_scope — adds a third, explicit per-board
-- grant level for Agent API tokens (competitor-parity issue 15): 'moderate'
-- (status change, tag assign/remove, merge/unmerge, pending-idea reads) on
-- top of 'read' and 'write' (migration 0047). Existing grants are
-- unchanged — no token gains moderator rights without an admin explicitly
-- issuing a new one. VARCHAR(10) already fits the new value.
ALTER TABLE api_token_boards
    DROP CONSTRAINT chk_api_token_boards_scope;

ALTER TABLE api_token_boards
    ADD CONSTRAINT chk_api_token_boards_scope CHECK (scope IN ('read', 'write', 'moderate'));
