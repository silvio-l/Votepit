-- 0083_add_idea_premoderation — optional per-board approval queue
-- (broaden-use-cases issue 05). Default OFF: every existing board keeps
-- publishing ideas immediately, exactly as before.
--
-- boards.premoderation: 'off' (default) | 'all' (every idea submitted by a
-- non-moderator needs approval). Deliberately a VARCHAR with an app-side
-- allow-list (Domain\Premoderation::MODES) and NO CHECK constraint, so a
-- later mode (issue 06: 'anonymous') needs no schema change. An unknown
-- value read back is treated fail-secure as "approval required".
ALTER TABLE boards ADD COLUMN premoderation VARCHAR(16) NOT NULL DEFAULT 'off' AFTER anonymous_voting_enabled;

-- ideas.moderation_state: 'published' | 'pending'. Every existing row is
-- 'published' via the column default (no backfill needed). A pending idea
-- is invisible on every read path: IdeaRepository's reads filter on
-- moderation_state = 'published' by default, with an explicit opt-in only
-- for the moderation queue and the author's / a moderator's detail view.
-- approved_at / approved_by record who published a pending idea and when
-- (NULL for ideas that never needed approval).
ALTER TABLE ideas
    ADD COLUMN moderation_state VARCHAR(10) NOT NULL DEFAULT 'published' AFTER status,
    ADD COLUMN approved_at DATETIME NULL AFTER moderation_state,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER approved_at;

ALTER TABLE ideas
    ADD KEY idx_ideas_board_moderation_state (board_id, moderation_state),
    ADD CONSTRAINT fk_ideas_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT chk_ideas_moderation_state CHECK (moderation_state IN ('published', 'pending'));

-- Two new in-app notification types: 'idea_approved' (to the author, on
-- approval) and 'ideas_pending_approval' (ONE aggregated notice per
-- moderator while unread — never one per idea, no mail flood).
ALTER TABLE notifications
    DROP CHECK chk_notifications_type;

ALTER TABLE notifications
    ADD CONSTRAINT chk_notifications_type CHECK (type IN ('support_reply', 'announcement', 'idea_comment', 'thread_reply', 'idea_status_changed', 'abuse_report_submitted', 'idea_approved', 'ideas_pending_approval'));
