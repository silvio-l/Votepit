-- 0076_add_idea_merge_columns — merging duplicate ideas (competitor-parity
-- issue 10). ideas.merged_into_id already exists since the baseline
-- (fk_ideas_merged, ON DELETE SET NULL, which also gives InnoDB its index);
-- this adds who merged and when, so the merge is auditable on the row and
-- an unmerge can reset it completely.
--
--   merged_at : when the idea was folded into merged_into_id (NULL = not merged).
--   merged_by : the moderating member who merged it. ON DELETE SET NULL —
--               the audit log keeps the actor id; user erasure must not be
--               blocked by a moderation record.
--
-- Votes are never moved or copied on merge (unmerge stays lossless): the
-- canonical idea's score_cache/up_count/down_count become the aggregate over
-- the group with one vote per voter (VoteRepository::recomputeMergeGroup()).
ALTER TABLE ideas ADD COLUMN merged_at DATETIME NULL AFTER merged_into_id;
ALTER TABLE ideas ADD COLUMN merged_by BIGINT UNSIGNED NULL AFTER merged_at;
ALTER TABLE ideas ADD CONSTRAINT fk_ideas_merged_by FOREIGN KEY (merged_by) REFERENCES users(id) ON DELETE SET NULL;
