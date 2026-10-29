-- 0022_add_user_username — optional, globally unique display name
-- (profile-visibility feature, follow-up). NULL by default: a user with no
-- username still shows as the generic "Voter" placeholder wherever their
-- profile is visible (mirrors the profile_visible default in 0021). Only
-- ever shown on public surfaces when profile_visible = 1 — see
-- UserRepository::findPublicProfileById() / AuthorBadge, which fall back to
-- "Voter" otherwise, same as an anonymous profile.
--
-- Global uniqueness (not per-account) — `users` itself is global (ADR 0001
-- §2c), and a username is a property of the person, not of one membership.
-- username_lower is application-maintained (UserRepository::setUsername(),
-- always written alongside username) rather than a DB-generated column —
-- keeps the exact same write path portable to the SQLite test schema
-- instead of depending on MySQL-specific generated-column behavior, while
-- still getting a real DB-level uniqueness guarantee against races.
ALTER TABLE users ADD COLUMN username VARCHAR(30) NULL AFTER profile_visible;
ALTER TABLE users ADD COLUMN username_lower VARCHAR(30) NULL AFTER username;
ALTER TABLE users ADD UNIQUE INDEX idx_users_username_lower (username_lower);
