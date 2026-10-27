-- 0115_add_boards_status_reason_required — per-board switch "a reason is
-- required when an idea's status is set" (owner decision 2026-10-26).
--
-- boards.status_reason_required: same NULL-free TINYINT(1) opt-in pattern as
-- 0055/0068/0099, default OFF — an existing board keeps accepting status
-- changes without a reason until its owner or an admin switches this on.
-- Enforced server-side in IdeaStatusAction (422 status_reason_required);
-- automated issue-tracker sync is exempt (no human decision text).
ALTER TABLE boards ADD COLUMN status_reason_required TINYINT(1) NOT NULL DEFAULT 0 AFTER vote_reasons_enabled;
