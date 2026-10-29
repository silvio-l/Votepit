-- 0118_add_account_member_suspension — team seats above the current plan's
-- member_limit are suspended instead of silently keeping their rights.
--
-- Written by Votepit\Domain\PlanLimitReconciler (after every plan change):
-- the owner always keeps access; the remaining seats go to the
-- longest-standing members (created_at, then user_id). A suspended member is
-- treated as a non-member by AccountMemberRepository::roleFor(), so every
-- AuthZ gate, private-board check and moderation path denies them without any
-- further change. Restored automatically when the plan allows the seat again.
ALTER TABLE account_members ADD COLUMN suspended_at DATETIME NULL AFTER role;

-- Boards frozen because the plan no longer allows their visibility (e.g.
-- unlisted on Starter) carry frozen_reason = 'visibility', so the reconciler
-- can lift exactly those freezes again on an upgrade. Board-limit freezes and
-- the owner's own active-board choice keep frozen_reason NULL (owner re-chooses).
ALTER TABLE boards ADD COLUMN frozen_reason VARCHAR(16) NULL AFTER frozen_at;
