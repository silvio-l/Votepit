-- 0016_add_board_freeze_and_deletion_reminder — Sprint 25: upgrade/downgrade/
-- cancellation lifecycle.
--
-- boards.frozen_at: DELIBERATELY a SECOND, distinct column from
-- boards.locked_at (migrations/0013_add_operator_panel.sql). locked_at is the
-- operator kill-switch: it makes a board structurally unfindable even on its
-- own public page (BoardRepository::findPublicBySlugForAccount()'s
-- `b.locked_at IS NULL` clause). A downgrade-frozen board is the opposite
-- shape: it MUST stay fully readable (public page, ideas, comments all still
-- render) — only writes are rejected (idea submit/vote/comment/edit/status/
-- pin — see the inline `frozen_at !== null` guards added to those Http\Action
-- classes in this sprint). Reusing locked_at would have silently hidden every
-- downgrade-frozen board from the public, which is explicitly NOT
-- wanted ("read-only/archived", not deleted/hidden). The two reasons a
-- board goes read-only are also operationally independent and must be able
-- to coexist: an operator can lock a board that is separately downgrade-
-- frozen, and unlocking it must not silently unfreeze it (and vice versa).
ALTER TABLE boards ADD COLUMN frozen_at DATETIME NULL AFTER locked_at;

-- accounts.deletion_reminder_sent_at: guards the cancellation export-reminder
-- mail against being resent on every subsequent owner login (see
-- AppFactory's POST /login handler). Paired with accounts.deletion_scheduled_at,
-- which has existed unused since migrations/0001_create_account_schema.sql —
-- this sprint is the first to actually write/read it. Both columns are
-- cleared together by AccountRepository::clearDeletionSchedule().
ALTER TABLE accounts ADD COLUMN deletion_reminder_sent_at DATETIME NULL AFTER deletion_scheduled_at;
