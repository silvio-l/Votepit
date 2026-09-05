-- 0024_add_notifications_remove_support_email — replaces the email-based
-- support-request contact channel (0023) with an entirely in-app one, per
-- explicit product decision: Votepit never asks a customer for an email
-- address anywhere in the support flow, and the operator reply/announcement
-- channel back to the customer stays inside the dashboard, not a mailbox.
--
-- support_requests.contact_email_enc is dropped outright rather than kept
-- nullable-and-unused: the feature shipped same-day, only to staging (never
-- production), so there is no real customer data to preserve.
ALTER TABLE support_requests DROP COLUMN contact_email_enc;

-- notifications: a customer's in-app inbox. Two shapes share one table
-- (`scope` discriminates), mirroring how ideas/boards already carry a
-- single row shape for multiple use cases rather than splitting tables per
-- variant:
--   - scope='account' (account_id NOT NULL): targeted at one account's
--     members, e.g. "your support ticket got a reply" (SupportRequestAction
--     ::operatorReply). created_by is NULL — system-generated, not
--     operator-authored content.
--   - scope='broadcast' (account_id NULL): visible to every account,
--     e.g. an operator-authored announcement (OperatorAnnouncementAction).
--     created_by is the operator user id.
--
-- link_path is an in-app relative path (e.g. '/admin/support') the SPA
-- navigates to when the notification is clicked — never an external URL,
-- consistent with the in-app-only channel decision above.
--
-- Read state lives in notification_reads (one row per user per
-- notification actually read) rather than a boolean column on
-- notifications itself, because a single row can target many users (every
-- member of an account, or literally everyone for a broadcast) who each
-- need their own independent read state.
CREATE TABLE IF NOT EXISTS notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope       VARCHAR(16)     NOT NULL,
    account_id  BIGINT UNSIGNED NULL,
    type        VARCHAR(32)     NOT NULL,
    title       VARCHAR(200)    NOT NULL,
    body        TEXT            NOT NULL,
    link_path   VARCHAR(300)    NULL,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_account (account_id, created_at),
    KEY idx_notifications_scope (scope, created_at),
    CONSTRAINT fk_notifications_account    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_created_by FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT chk_notifications_scope CHECK (scope IN ('account', 'broadcast')),
    CONSTRAINT chk_notifications_type  CHECK (type IN ('support_reply', 'announcement')),
    CONSTRAINT chk_notifications_scope_account CHECK (
        (scope = 'broadcast' AND account_id IS NULL) OR (scope = 'account' AND account_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_reads (
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    read_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id),
    CONSTRAINT fk_notification_reads_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_reads_user         FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
