-- 0060_add_idea_moderation_columns — board-operator-compliance issue 07.
--
-- ideas soft-delete: same shape as 0059's comments columns (deleted_at +
-- moderation_action_id, both nullable — most ideas are never moderated).
-- deleted_at marks a moderated idea hidden from every public/read path
-- (IdeaRepository::findInBoard()/listByBoard()/roadmapByBoard()) while the
-- row itself stays in place; moderation_action_id links it back to the
-- content_moderation_actions row (migrations/0059) that recorded the
-- reason/snapshot, for the author-notification email and a future appeal
-- review UI. content_moderation_actions.entity_type already accepts
-- 'idea' (0059's CHECK constraint) — this migration only adds the columns
-- 'idea' rows get soft-deleted through.
ALTER TABLE ideas
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
    ADD COLUMN moderation_action_id BIGINT UNSIGNED NULL AFTER deleted_at;

ALTER TABLE ideas
    ADD KEY idx_ideas_moderation_action (moderation_action_id),
    ADD CONSTRAINT fk_ideas_moderation_action
        FOREIGN KEY (moderation_action_id) REFERENCES content_moderation_actions(id) ON DELETE SET NULL;
