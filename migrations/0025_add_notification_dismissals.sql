-- 0025_add_notification_dismissals — lets a user permanently remove a
-- notification from their own inbox view, per explicit product request:
-- announcements/support replies should not stay in the inbox forever once
-- read.
--
-- Mirrors notification_reads exactly (one row per user per notification),
-- because a single notifications row can target many users (every account
-- member, or literally everyone for a broadcast) who each need their own
-- independent dismissal state — dismissing a broadcast must not remove it
-- from anyone else's inbox, and never deletes the underlying notification
-- itself (that stays reserved for OperatorAnnouncementAction's global
-- broadcast delete).
CREATE TABLE IF NOT EXISTS notification_dismissals (
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    dismissed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id),
    CONSTRAINT fk_notification_dismissals_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_dismissals_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
