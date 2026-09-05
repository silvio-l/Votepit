-- 0043_add_account_admin_role — third account_members.role value: 'admin',
-- an intermediate tier between 'owner' and 'moderator'. Can manage boards
-- (create/branding/rename/moderation-settings/block/SMTP/API tokens) and
-- handle the account's support requests, but — unlike 'owner' — cannot
-- manage membership/invites/account settings/export/deletion. Existing
-- 'moderator' rows are NOT auto-upgraded (deliberate product decision):
-- 'moderator' is narrowed going forward to comment/idea moderation only
-- (enforced in AuthZMiddleware/AppFactory, not by this migration).
ALTER TABLE account_members
    DROP CHECK chk_account_members_role;

ALTER TABLE account_members
    ADD CONSTRAINT chk_account_members_role CHECK (role IN ('owner', 'admin', 'moderator'));
