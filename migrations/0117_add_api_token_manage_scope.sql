-- 0117_add_api_token_manage_scope — adds the 'manage' grant above 'moderate'
-- for Agent API tokens: board branding and board images (read + write of
-- the same fields the app edits). Effective only while the token's issuing
-- user is still an account owner or admin (see ApiTokenAuthMiddleware).
-- Existing grants are unchanged. VARCHAR(10) already fits the new value.
ALTER TABLE api_token_boards
    DROP CONSTRAINT chk_api_token_boards_scope;

ALTER TABLE api_token_boards
    ADD CONSTRAINT chk_api_token_boards_scope CHECK (scope IN ('read', 'write', 'moderate', 'manage'));
