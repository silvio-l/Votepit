-- 0084_add_anonymous_idea_submission — per-board opt-in "submit an idea
-- without an account" (broaden-use-cases issue 06, ADR 0006).
--
-- Every column is defaulted/nullable: existing boards, ideas and users stay
-- byte-identical, no backfill.
--
-- boards.anonymous_submission_enabled: same NULL-free TINYINT(1) opt-in as
-- anonymous_voting_enabled (0068), default OFF. Only effective together
-- with a configured Turnstile and a plan that allows it (checked app-side
-- on every request, the flag itself survives a downgrade).
ALTER TABLE boards ADD COLUMN anonymous_submission_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER premoderation;

-- ideas.anon_author_token_hash: HMAC-SHA256("anon-idea-author:" . account_id
-- . ":" . raw cookie token, identity_server_key) — Security\AnonymousAuthorHasher.
-- Set only on guest ideas (author_id = the guest placeholder; invariant kept
-- app-side, a CHECK cannot know the placeholder id). Never the raw token,
-- never exported. Nulled after 180 days (cookie lifetime) by the existing
-- daily bin/cleanup-expired-accounts.php run.
ALTER TABLE ideas ADD COLUMN anon_author_token_hash CHAR(64) NULL AFTER author_id;
ALTER TABLE ideas ADD KEY idx_ideas_board_anon_author (board_id, anon_author_token_hash);

-- users.is_guest_placeholder: marks the single, installation-wide "Guest"
-- system user every guest idea is attributed to (ADR 0006 §1) — kept apart
-- from is_deleted_placeholder because "guest" and "deleted user" render
-- and behave differently.
ALTER TABLE users ADD COLUMN is_guest_placeholder TINYINT(1) NOT NULL DEFAULT 0 AFTER is_deleted_placeholder;
