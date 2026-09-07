-- 0028_add_user_scoped_notifications — third notifications shape: single-
-- recipient targeting (`.scratch/notification-preferences/PRD.md`).
--
-- Two new event types, both fanned out directly from CommentCreateAction
-- after a successful insert (no central event bus, matching the existing
-- account/broadcast pattern — see migrations/0024):
--   - idea_comment:  the idea's author, when someone else comments on it.
--   - thread_reply:  every DISTINCT prior commenter on the idea (excluding
--     the new commenter and the idea author, who is already covered
--     exclusively by idea_comment above — this is the deduplication rule,
--     see PRD "Implementation Decisions").
--
-- scope='user' rows carry BOTH account_id and user_id (unlike 'broadcast'
-- which has neither and 'account' which has only account_id): the event is
-- always board/account-bound, and keeping account_id lets any future
-- account-scoped listing/cleanup reuse the existing idx_notifications_account
-- index instead of adding a parallel one.
ALTER TABLE notifications
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER account_id,
    ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE notifications
    DROP CHECK chk_notifications_scope,
    DROP CHECK chk_notifications_type,
    DROP CHECK chk_notifications_scope_account;

ALTER TABLE notifications
    ADD CONSTRAINT chk_notifications_scope CHECK (scope IN ('account', 'broadcast', 'user')),
    ADD CONSTRAINT chk_notifications_type  CHECK (type IN ('support_reply', 'announcement', 'idea_comment', 'thread_reply')),
    ADD CONSTRAINT chk_notifications_scope_account CHECK (
        (scope = 'broadcast' AND account_id IS NULL AND user_id IS NULL)
        OR (scope = 'account' AND account_id IS NOT NULL AND user_id IS NULL)
        OR (scope = 'user' AND account_id IS NOT NULL AND user_id IS NOT NULL)
    );

CREATE INDEX idx_notifications_user ON notifications (user_id, created_at);
