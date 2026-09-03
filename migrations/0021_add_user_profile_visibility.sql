-- 0021_add_user_profile_visibility — per-user privacy toggle (profile
-- visibility feature). Default 0 (anonymous): every user starts anonymous
-- until they explicitly opt in to a visible profile in Privacy settings.
--
-- When 0, public surfaces (idea author, comment author, member lists) show
-- only a generic "Voter" placeholder — no avatar, no social links, no name
-- (there is no name to begin with, ADR 0002). When 1, the user's avatar +
-- social links become visible to anyone who can already see the idea/comment
-- (those reads are anon-gated / public, see AuthZMiddleware::anon()).
ALTER TABLE users ADD COLUMN profile_visible TINYINT(1) NOT NULL DEFAULT 0 AFTER avatar_filename;
