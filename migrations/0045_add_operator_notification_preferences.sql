-- 0045_add_operator_notification_preferences — lets an operator/support
-- agent individually opt in/out of the two operator-scoped notification
-- kinds (migrations/0041_add_operator_scoped_notifications.sql), same
-- independent in-app/email pattern as the existing four idea_comment/
-- thread_reply/idea_status preference columns (migrations/0029, 0034):
--
--   - notify_abuse_report_*:  a new abuse report was submitted
--     (AbuseReportAction::submit(), new 'abuse_report_submitted' type below).
--   - notify_support_ticket_*: a new support ticket was opened, or a
--     customer replied (SupportRequestAction, reuses the existing
--     'support_reply' type — see 0041's class doc for why scope carries the
--     recipient side, not type).
--
-- In-app defaults to ON (opt-out, matches the pre-existing unconditional
-- behavior these columns now gate) — an operator/support agent who never
-- visits /profile keeps seeing exactly what they already see today. Email
-- defaults to OFF (opt-in), same as every other *_email column.
ALTER TABLE users
    ADD COLUMN notify_abuse_report_inapp   TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_idea_status_email,
    ADD COLUMN notify_abuse_report_email   TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_abuse_report_inapp,
    ADD COLUMN notify_support_ticket_inapp TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_abuse_report_email,
    ADD COLUMN notify_support_ticket_email TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_support_ticket_inapp;

ALTER TABLE notifications
    DROP CHECK chk_notifications_type;

ALTER TABLE notifications
    ADD CONSTRAINT chk_notifications_type CHECK (type IN ('support_reply', 'announcement', 'idea_comment', 'thread_reply', 'idea_status_changed', 'abuse_report_submitted'));
