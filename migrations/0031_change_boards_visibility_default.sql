-- 0031_change_boards_visibility_default — fail-secure default (CLAUDE.md §🔒).
--
-- BoardCreateAction/SignupAccountAction now always set boards.visibility
-- explicitly on INSERT (via PlanPolicy::defaultVisibility() when the caller
-- did not choose one), so this DDL is defense-in-depth only: it changes what
-- an unrelated/future INSERT that omits the column would fall back to, from
-- 'public' to the most restrictive value, 'private'. Existing rows are
-- untouched — MODIFY only changes the column default, not stored data.
ALTER TABLE boards MODIFY visibility VARCHAR(16) NOT NULL DEFAULT 'private';
