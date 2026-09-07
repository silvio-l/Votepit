-- 0046_add_account_member_role — fourth account_members.role value:
-- 'member'. A plain team member with NO moderation/admin rights at all —
-- the sole purpose is private-board access for someone who isn't a public
-- voter (BoardRepository's viewerIsMember check is `roleFor(...) !== null`,
-- so 'member' already grants private-board visibility without any code
-- change there; AuthZMiddleware::accountAdmin()/accountModerate() enumerate
-- their allowed roles explicitly, so 'member' is excluded from both by
-- construction, same mechanism as any other role not listed).
--
-- Also widens invites.role: it previously only ever allowed 'moderator'
-- (every invite was hardcoded to that role) — InviteAction now lets the
-- inviting owner pick 'member', 'moderator', or 'admin' (never 'owner',
-- unchanged).
ALTER TABLE account_members
    DROP CHECK chk_account_members_role;

ALTER TABLE account_members
    ADD CONSTRAINT chk_account_members_role CHECK (role IN ('owner', 'admin', 'moderator', 'member'));

ALTER TABLE invites
    DROP CHECK chk_invites_role;

ALTER TABLE invites
    ADD CONSTRAINT chk_invites_role CHECK (role IN ('member', 'moderator', 'admin'));
