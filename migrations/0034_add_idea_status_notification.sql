-- 0034_add_idea_status_notification — fourth notification event type
-- (`.scratch/social-features/issues/05-idea-status-follow-notification.md`):
-- 'idea_status_changed', scope 'user', fanned out directly from
-- IdeaStatusAction on every real (non-no-op) status change, same
-- "no central event bus" pattern as idea_comment/thread_reply
-- (migrations/0028_add_user_scoped_notifications.sql).
--
-- Recipients: the idea's author + every DISTINCT voter + every DISTINCT
-- commenter, deduplicated (a user in multiple roles gets exactly one row),
-- excluding the admin who triggered the change. A no-op (from === to) or
-- an invalid-transition attempt writes nothing (IdeaStatusAction returns
-- before the transaction in both cases).
--
-- Two new preference flags mirror the existing four idea_comment/
-- thread_reply columns exactly (migrations/0029_add_notification_email_
-- preferences.sql): in-app default ON (opt-out), email default OFF
-- (opt-in), independent of the existing comment preferences.
ALTER TABLE users
    ADD COLUMN notify_idea_status_inapp TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_thread_reply_email,
    ADD COLUMN notify_idea_status_email TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_idea_status_inapp;

ALTER TABLE notifications
    DROP CHECK chk_notifications_type;

ALTER TABLE notifications
    ADD CONSTRAINT chk_notifications_type CHECK (type IN ('support_reply', 'announcement', 'idea_comment', 'thread_reply', 'idea_status_changed'));
