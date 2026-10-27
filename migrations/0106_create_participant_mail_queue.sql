-- 0106_create_participant_mail_queue — pending participant mail items
-- (close-the-loop slice 03, re-engagement C). One row per (recipient, change):
-- a status change, a new comment or a thread reply that the recipient wants
-- batched (daily/weekly) instead of instant. ParticipantDigestService bundles
-- all pending rows of one recipient into at most one mail per period and
-- marks them handled; rows are never dropped by a deferred or failed run.
--
-- Recipients are either a registered user (recipient_user_id) or a guest with
-- a stored anonymous-vote notification e-mail (recipient_key = keyed HMAC of
-- the normalised address, recipient_email_enc = the address, encrypted with
-- the anon_vote_email context, ADR 0004). Exactly one of the two is set.
--
-- payload is a JSON object of plain strings only (idea title, status label,
-- decision reason, comment excerpt), length-bounded at write time. The text
-- is NOT pre-rendered: the digest composes the sentence in the recipient's
-- language and renders it only through MailTemplate (HTML-escaped). Handled
-- rows are purged after 30 days by the digest job (retention, docs/legal.md).
CREATE TABLE IF NOT EXISTS participant_mail_queue (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_user_id   BIGINT UNSIGNED NULL,
    recipient_key       CHAR(64)        NULL,
    recipient_email_enc TEXT            NULL,
    category            VARCHAR(16)     NOT NULL,
    board_id            BIGINT UNSIGNED NOT NULL,
    idea_id             BIGINT UNSIGNED NOT NULL,
    event_kind          VARCHAR(16)     NOT NULL,
    payload             TEXT            NOT NULL,
    link_path           VARCHAR(255)    NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at          DATETIME        NULL,
    outcome             VARCHAR(16)     NULL,
    PRIMARY KEY (id),
    KEY idx_pmq_user_pending (handled_at, recipient_user_id),
    KEY idx_pmq_guest_pending (handled_at, recipient_key),
    KEY idx_pmq_idea (idea_id),
    KEY idx_pmq_handled (handled_at),
    CONSTRAINT fk_pmq_user  FOREIGN KEY (recipient_user_id) REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_pmq_board FOREIGN KEY (board_id)          REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_pmq_idea  FOREIGN KEY (idea_id)           REFERENCES ideas(id)  ON DELETE CASCADE,
    CONSTRAINT chk_pmq_recipient CHECK (
        (recipient_user_id IS NOT NULL AND recipient_key IS NULL) OR
        (recipient_user_id IS NULL AND recipient_key IS NOT NULL)
    ),
    CONSTRAINT chk_pmq_category CHECK (category IN ('my_ideas', 'participated', 'new_ideas'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
