-- 0061_create_content_moderation_appeals — board-operator-compliance issue
-- 09: the appeal path promised by issue 06/07's removal-notification email
-- and reserved by issue 08's ModerationPurgeService::hasPendingAppeal()
-- extension point.
--
-- One row per appeal attempt against a content_moderation_actions row (no
-- FK — polymorphic-adjacent style consistency with content_moderation_
-- actions itself is not needed here since moderation_action_id always
-- targets the same single table, but a real FK keeps an appeal from ever
-- pointing at a moderation action that doesn't exist).
--
-- "One open appeal per moderation action" (PRD) is enforced at the
-- application layer (ModerationAppealRepository::hasPendingAppeal(), checked
-- by ModerationAppealAction::submit() before insert) rather than as a
-- partial-unique index — MySQL has no native partial/filtered UNIQUE index,
-- and the generated-column workaround (a virtual column that is NULL unless
-- status='pending', UNIQUE over (moderation_action_id, that column)) would
-- add real schema complexity for a case that is already fully covered by an
-- app-level check-then-insert inside the same short-lived request that
-- already re-reads the moderation action row anyway.
--
-- status: 'pending' until a moderator/admin resolves it to 'upheld' (the
-- removal stands) or 'overturned' (removal was wrong — reserved for a
-- possible future ticket that restores the content; this ticket only
-- records the resolution, it does not un-delete anything).
--
-- resolved_by_user_id/resolved_at: both NULL until resolve(), both set
-- together (ModerationAppealRepository::resolve()).
CREATE TABLE content_moderation_appeals (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    moderation_action_id  BIGINT UNSIGNED NOT NULL,
    submitted_by_user_id  BIGINT UNSIGNED NOT NULL,
    message               TEXT            NOT NULL,
    status                VARCHAR(16)     NOT NULL DEFAULT 'pending',
    resolved_by_user_id   BIGINT UNSIGNED NULL,
    resolved_at           DATETIME        NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cma_appeals_moderation_action (moderation_action_id),
    KEY idx_cma_appeals_submitted_by (submitted_by_user_id),
    CONSTRAINT chk_cma_appeals_status CHECK (status IN ('pending', 'upheld', 'overturned')),
    CONSTRAINT fk_cma_appeals_moderation_action
        FOREIGN KEY (moderation_action_id) REFERENCES content_moderation_actions(id) ON DELETE CASCADE,
    CONSTRAINT fk_cma_appeals_submitted_by
        FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cma_appeals_resolved_by
        FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
