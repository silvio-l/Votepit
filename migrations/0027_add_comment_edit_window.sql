-- 0027_add_comment_edit_window — supports two anti-spam-adjacent behaviours
-- on comments: (1) rejecting a second comment posted by the same author on
-- the same idea back-to-back (checked at write time against the existing
-- created_at of the author's most recent comment on that idea — no schema
-- change needed for that part), and (2) letting the author edit their own
-- comment for a short window after posting, to fix typos without opening a
-- new consecutive-comment spam vector. edited_at stays NULL until the first
-- edit, then records when the comment was last edited (surfaced to voters
-- as a "bearbeitet" indicator) — separate from created_at, which must stay
-- the original post time for the anti-spam consecutive-comment check above.
ALTER TABLE comments
    ADD COLUMN edited_at DATETIME NULL AFTER created_at;
