-- 0074_add_user_self_service_deletion — GDPR self-service for every user
-- (export + delete own user account; competitor-parity issue 12).
--
--   deletion_scheduled_at  : 48h grace period after POST /account/delete
--                            (same window as the owner-initiated account
--                            deletion, accounts.deletion_scheduled_at).
--                            bin/cleanup-expired-accounts.php erases the
--                            user once it has passed (ExpiredUserCleaner).
--   is_deleted_placeholder : marks the single shared "deleted user" row that
--                            ideas/comments (and RESTRICT-referenced audit
--                            rows) of an erased user are re-attributed to.
--                            Created lazily by
--                            UserRepository::findOrCreateDeletedPlaceholder()
--                            with a non-HMAC email_hmac sentinel (can never
--                            log in) and every notification flag off.
ALTER TABLE users ADD COLUMN is_deleted_placeholder TINYINT(1) NOT NULL DEFAULT 0 AFTER is_test_account;
ALTER TABLE users ADD COLUMN deletion_scheduled_at DATETIME NULL AFTER created_at;
ALTER TABLE users ADD KEY idx_users_deletion_scheduled_at (deletion_scheduled_at);
