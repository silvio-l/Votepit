-- 0070_add_voter_identity_links — closes a double-vote gap found via live
-- testing: sign in and vote, sign out, then vote again on the SAME idea
-- while anonymous (or the reverse order) currently counts as two separate
-- votes, since an authenticated vote is keyed by votes.user_id and an
-- anonymous one by votes.voter_token_hash — two structurally independent
-- identities with no link between them (ADR 0004).
--
-- voter_identity_links records, the first time an anonymous-voter cookie is
-- seen alongside an authenticated vote, which user_id that cookie's HMAC
-- (the same value as votes.voter_token_hash) belongs to. VoteAction then,
-- on any later anonymous vote request carrying a linked cookie, casts under
-- that user_id instead of creating a second voter_token_hash identity —
-- collapsing both paths onto exactly one votes row per (idea, person),
-- regardless of login state at the time of any individual vote.
--
-- Global (not board/account-scoped), same as votes.voter_token_hash itself:
-- the cookie is one browser-wide identity token, not tied to a single board.
CREATE TABLE voter_identity_links (
    voter_token_hash CHAR(64)       NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    linked_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (voter_token_hash),
    KEY idx_voter_identity_links_user (user_id),
    CONSTRAINT fk_voter_identity_links_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
