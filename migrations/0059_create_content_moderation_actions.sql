-- 0059_create_content_moderation_actions — board-operator-compliance issue
-- 06: reason-coded, appealable moderation, shared across content types.
--
-- content_moderation_actions is the single source of truth for both "what
-- was removed and why" (DSA Art. 17 justification, content_snapshot kept
-- for an appeal reviewer) and "how many times has this member been
-- moderated" (repeat-offender counting, issue 08) — one row, two future
-- consumers, rather than a separate audit log and a separate counter
-- table. entity_type/entity_id is a polymorphic reference (no FK — 'idea'
-- and 'comment' live in different tables); board_id/account_id are always
-- populated directly (not derived via a JOIN through entity_id) so this
-- table stays queryable/scopable on its own, same shape as every other
-- account-scoped table in this codebase.
--
-- actor_tier: 'board_moderator' is the only value this ticket (06) ever
-- writes — every role that passes AuthZMiddleware::accountModerate()
-- (owner/admin/moderator) moderates in the capacity of "this account's
-- board operator", not as Votepit itself. 'operator' is reserved for a
-- later platform-wide Trust & Safety moderation action (user story 17
-- only asks for the Operator panel to see existing removals across
-- accounts, not to file its own — no such action is built here).
--
-- reason_code: the same 7-value enum the frontend moderation form and
-- issue 07's appeal review both key off of.
--
-- purge_after: created_at + 30 days fixed (see PRD "Further Notes" —
-- the 14-day appeal extension is issue 07/08's concern once
-- content_moderation_appeals exists; this ticket only ever writes the
-- fixed 30-day value). The purge job itself ships in issue 05/08.
CREATE TABLE content_moderation_actions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type      VARCHAR(16)     NOT NULL,
    entity_id        BIGINT UNSIGNED NOT NULL,
    board_id         BIGINT UNSIGNED NOT NULL,
    account_id       BIGINT UNSIGNED NOT NULL,
    actor_user_id    BIGINT UNSIGNED NOT NULL,
    actor_tier       VARCHAR(16)     NOT NULL,
    reason_code      VARCHAR(32)     NOT NULL,
    reason_note      TEXT            NULL,
    content_snapshot TEXT            NOT NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    purge_after      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cma_account (account_id),
    KEY idx_cma_board (board_id),
    KEY idx_cma_entity (entity_type, entity_id),
    KEY idx_cma_purge_after (purge_after),
    CONSTRAINT chk_cma_entity_type CHECK (entity_type IN ('idea', 'comment')),
    CONSTRAINT chk_cma_actor_tier CHECK (actor_tier IN ('board_moderator', 'operator')),
    CONSTRAINT chk_cma_reason_code CHECK (reason_code IN (
        'community_rule', 'illegal_copyright', 'illegal_privacy', 'illegal_threat',
        'illegal_fraud', 'illegal_other', 'other'
    )),
    CONSTRAINT fk_cma_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_cma_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cma_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- comments soft-delete: deleted_at marks a moderated comment hidden from
-- every public/read path (CommentRepository::listByIdea() etc.) while the
-- row itself stays in place; moderation_action_id links it back to the
-- row above (the "why", for the author-notification email and, later, the
-- appeal review UI). Both nullable — most comments are never moderated.
ALTER TABLE comments
    ADD COLUMN deleted_at DATETIME NULL AFTER edited_at,
    ADD COLUMN moderation_action_id BIGINT UNSIGNED NULL AFTER deleted_at;

ALTER TABLE comments
    ADD KEY idx_comments_moderation_action (moderation_action_id),
    ADD CONSTRAINT fk_comments_moderation_action
        FOREIGN KEY (moderation_action_id) REFERENCES content_moderation_actions(id) ON DELETE SET NULL;
