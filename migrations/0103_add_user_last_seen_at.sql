-- 0103_add_user_last_seen_at — last app visit per user (close-the-loop,
-- re-engagement G: decay of participant mails by last visit, no tracking in
-- mails). Written by AuthNMiddleware at most once per hour per user
-- (UserRepository::touchLastSeen()). NULL = no visit recorded since this
-- migration; the decay then falls back to users.created_at. Forward-only.
ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL AFTER digest_processed_at;
