-- 0029_add_notification_email_preferences — opt-in notification-email
-- channel + per-event-type preferences (.scratch/notification-preferences/PRD.md,
-- issue 02). Builds on 0028's user-scoped notifications (idea_comment,
-- thread_reply): each user can now choose, per event type, whether they
-- receive it in-app, by email, or both — default in-app on, email off
-- (pure opt-in).
--
-- notification_email is a NEW, deliberately separate plaintext PII field
-- (ADR 0002 Amendment §6) — never the identity email_hmac is derived from,
-- never used for login. It is set ONLY via the confirmation-link flow
-- (notification_email_verifications below): there is no write path that
-- puts an unconfirmed address into this column, so "column is non-NULL"
-- always means "confirmed". Removing the address (DELETE
-- /account/notification-email) clears this column and forces both
-- *_email flags back to 0 in the same statement/transaction — a flag can
-- never point at a NULL address.
ALTER TABLE users
    ADD COLUMN notification_email        VARCHAR(254) NULL        AFTER github_username,
    ADD COLUMN notify_idea_comment_inapp TINYINT(1) NOT NULL DEFAULT 1 AFTER notification_email,
    ADD COLUMN notify_idea_comment_email TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_idea_comment_inapp,
    ADD COLUMN notify_thread_reply_inapp TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_idea_comment_email,
    ADD COLUMN notify_thread_reply_email TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_thread_reply_inapp;

-- Verification tokens for a pending notification_email — same
-- token-crypto building blocks as login_tokens (TokenVault: 32 random
-- bytes, SHA-256 hash, single use), but a dedicated table rather than
-- reusing login_tokens: this token additionally carries the CANDIDATE
-- email address itself (not yet in `users`), which login_tokens has no
-- column for and shouldn't grow one just for this.
CREATE TABLE IF NOT EXISTS notification_email_verifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    email      VARCHAR(254) NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_email_verifications_token_hash (token_hash),
    KEY idx_notification_email_verifications_user (user_id),
    CONSTRAINT fk_notification_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
