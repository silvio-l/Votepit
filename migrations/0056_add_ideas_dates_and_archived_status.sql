-- 0056_add_ideas_dates_and_archived_status — Roadmap Gantt view follow-up.
--
-- The Roadmap (BoardRoadmapAction) only ever showed status columns; adding a
-- Gantt timeline view needs a start and a target date per idea to draw bars
-- against. Both are nullable — most ideas will never get a date, and an
-- idea with no target_date simply never appears in the Gantt (list/columns
-- views are unaffected either way). Board-admin-set only, gated behind
-- PlanPolicy::roadmapGanttAllowed() in the calling action (see
-- IdeaRepository::updateDates()) — the columns themselves are plain plan-
-- agnostic storage, same as every other idea column.
--
-- 'archived' is a new StatusService::VALID_STATUSES value (reachable only
-- from 'done'), not a schema change — status is already VARCHAR(16) and
-- 'archived' (8 chars) fits. Recorded here only as a comment for context:
-- archived ideas are excluded from the default board list
-- (IdeaRepository::listByBoard()) and from the roadmap/Gantt groups
-- (::roadmapByBoard() already restricts to planned/in_progress/done), but
-- stay queryable via the existing ?status=archived allow-list filter —
-- resource-conscious "put away", not a delete.
ALTER TABLE ideas
    ADD COLUMN start_date  DATE NULL AFTER status,
    ADD COLUMN target_date DATE NULL AFTER start_date;
