-- 0068_add_anonymous_voting — per-board opt-in "vote without an account"
-- (feature-ideas 2026-09-03 §5.1 follow-through, ADR 0004).
--
-- boards.anonymous_voting_enabled: same NULL-free TINYINT(1) opt-in pattern
-- as translation_enabled (migrations/0055), default OFF — an existing
-- board's behaviour is completely unchanged until its owner explicitly
-- flips this on.
ALTER TABLE boards ADD COLUMN anonymous_voting_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER translation_enabled;

-- votes.user_id becomes nullable: an anonymous vote row carries
-- voter_token_hash instead (mutually exclusive with user_id, see the CHECK
-- constraint below). The FK is dropped and re-added — MySQL allows a NULL
-- FK column without requiring a match, no ON DELETE change needed.
ALTER TABLE votes DROP FOREIGN KEY fk_votes_user;
ALTER TABLE votes MODIFY COLUMN user_id BIGINT UNSIGNED NULL;
ALTER TABLE votes ADD CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- voter_token_hash: HMAC-SHA256 (ADR 0004, domain-separated from every
-- other hasher via the "anon-vote-token:" prefix — see
-- Security\AnonymousVoterHasher) over the raw token stored ONLY in the
-- voter's httpOnly cookie. Never the raw token itself, same
-- pseudonymization discipline as users.email (ADR 0002).
ALTER TABLE votes ADD COLUMN voter_token_hash CHAR(64) NULL AFTER user_id;

-- voter_email_hash: the OPTIONAL, purely-for-notifications email an
-- anonymous voter may leave. Hashed with a DIFFERENT HMAC domain
-- ("anon-vote-email:") than users.email's login hash — critical: reusing
-- the same hash would let anyone holding a plaintext email list link an
-- anonymous vote back to a real platform account, defeating the entire
-- point of the anonymous path. See AnonymousVoterHasher::hashNotificationEmail().
ALTER TABLE votes ADD COLUMN voter_email_hash CHAR(64) NULL AFTER voter_token_hash;

-- Add a twin (idea_id, voter_token_hash) unique key alongside the existing
-- (idea_id, user_id) one. MySQL treats NULL as pairwise-distinct in a
-- UNIQUE index, so:
--   - an authenticated vote (user_id set, voter_token_hash NULL)   -> only
--     uq_votes_idea_user can ever collide for it (existing behaviour).
--   - an anonymous vote   (user_id NULL, voter_token_hash set)     -> only
--     uq_votes_idea_voter_token can ever collide for it.
-- Both indexes coexist without ever blocking the other identity kind's rows.
-- uq_votes_idea_user itself is deliberately left untouched (not dropped and
-- re-added): it's the ONLY index with idea_id as a leftmost column, so
-- InnoDB uses it to back fk_votes_idea (idea_id -> ideas.id) — dropping it,
-- even to immediately recreate an identical one, fails with error 1553
-- ("needed in a foreign key constraint") until the replacement index
-- exists first. Since its definition never actually changes, the simplest
-- fix is to just not touch it.
ALTER TABLE votes ADD UNIQUE KEY uq_votes_idea_voter_token (idea_id, voter_token_hash);

-- Exactly one identity column set per row, never both, never neither —
-- app-side (VoteRepository) already only ever writes one of the two, this
-- CHECK is defense-in-depth against any future write path that forgets it.
ALTER TABLE votes ADD CONSTRAINT chk_votes_identity CHECK (
    (user_id IS NOT NULL AND voter_token_hash IS NULL) OR
    (user_id IS NULL AND voter_token_hash IS NOT NULL)
);
