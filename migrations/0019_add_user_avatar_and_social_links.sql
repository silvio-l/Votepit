-- 0019_add_user_avatar_and_social_links — user-uploadable avatar + a small
-- bounded list of social links on the user's own profile (sprint
-- profile-avatar-social).
--
-- users.avatar_filename: an OPAQUE server-generated token (bin2hex(random_
-- bytes(16)) = 32 hex chars) plus a fixed extension for the re-encoded
-- format (".webp" or ".jpg") — never derived from user input. NULL means
-- "no avatar set" (the SPA falls back to a deterministic initials
-- placeholder, no third-party gravatar-style service, ADR 0002). The actual
-- bytes never live in the DB — this column is only a pointer into
-- storage/avatars/ (AvatarAction serves it via a dedicated route, never a
-- directly Apache-served static path with execute rights).
ALTER TABLE users ADD COLUMN avatar_filename VARCHAR(40) NULL AFTER is_blocked;

-- user_social_links: max 5 enforced in AccountProfileAction (application
-- layer — no DB-level CHECK needed for a bound this small). ON DELETE
-- CASCADE mirrors every other user-owned child table (login_tokens).
-- `label` is nullable plain text (rendered as escaped text only, never
-- markup); `url` is validated https://-only before it ever reaches this
-- table (SocialLinkValidator) and rendered only as an href attribute.
CREATE TABLE IF NOT EXISTS user_social_links (
    id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED  NOT NULL,
    label      VARCHAR(80)      NULL,
    url        VARCHAR(512)     NOT NULL,
    position   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_social_links_user (user_id),
    CONSTRAINT fk_user_social_links_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
