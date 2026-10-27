-- 0072_add_idea_counters — denormalized per-idea counters replacing the
-- correlated COUNT subqueries every board list / detail / roadmap / duplicate
-- read used to run per row (IdeaRepository). Same app-side pattern as
-- score_cache (ADR-3 amendment) and view_count (0049): no DB triggers, the
-- columns are maintained in the SAME transaction as the mutation —
--   up_count / down_count : VoteRepository (cast, castAnonymous, and the
--                           anonymous-row merge from 0070's identity links)
--   comment_count         : CommentRepository::create() (+1) and
--                           softDelete() (−1, moderation remove). A purge
--                           (hardDelete) only ever hits rows already
--                           soft-deleted, so it never changes the count.
-- comment_count counts only visible comments (deleted_at IS NULL), exactly
-- what the replaced subquery counted.
--
-- Signed INT (like score_cache), not UNSIGNED: should a counter ever drift,
-- a decrement below zero must not raise "out of range" in strict mode and
-- roll back a legitimate vote retraction.
--
-- Deploy note: apply together with the code that maintains the columns —
-- votes/comments written by old code between this backfill and the new
-- code going live would not be reflected. Re-running the final UPDATE
-- statement below re-syncs the counters at any time (idempotent).
ALTER TABLE ideas ADD COLUMN up_count INT NOT NULL DEFAULT 0 AFTER view_count;
ALTER TABLE ideas ADD COLUMN down_count INT NOT NULL DEFAULT 0 AFTER up_count;
ALTER TABLE ideas ADD COLUMN comment_count INT NOT NULL DEFAULT 0 AFTER down_count;
UPDATE ideas SET up_count = (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value > 0), down_count = (SELECT COUNT(*) FROM votes WHERE votes.idea_id = ideas.id AND votes.value < 0), comment_count = (SELECT COUNT(*) FROM comments WHERE comments.idea_id = ideas.id AND comments.deleted_at IS NULL);
