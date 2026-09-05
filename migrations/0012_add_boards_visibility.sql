-- 0012_add_boards_visibility — Sprint 21: Tier enforcement.
--
-- boards.visibility: 'public' (default — matches current behaviour exactly,
-- this column is additive, not breaking), 'unlisted' (not shown in any admin/
-- future public listing, but reachable by direct link — same anon-read gate
-- as 'public'), 'private' (only account members may view, even via the
-- anon-facing read routes — BoardHomeAction/BoardRoadmapAction/
-- IdeaDetailAction/IdeaNewAction, all of which already go through
-- BoardRepository::findPublicBySlugForAccount(), the confirm-
-- before-public chokepoint — extended in this sprint to also check
-- visibility instead of adding a second parallel check).
--
-- Setting this column to anything but 'public' is itself plan-gated
-- (PlanLimits::isVisibilityAllowed(), enforced in BoardBrandingAction) —
-- Free accounts cannot set unlisted/private; this DDL only adds the column,
-- it does not encode the gate.
ALTER TABLE boards ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'public' AFTER status;
ALTER TABLE boards ADD CONSTRAINT chk_boards_visibility CHECK (visibility IN ('public', 'unlisted', 'private'));
