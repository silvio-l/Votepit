-- 0098_add_upload_terms_consents — evidence that an owner/admin accepted the
-- upload clause (AGB clause 17, user-uploaded board images: logo, cover,
-- favicon, share image). Draft version, see Votepit\Domain\UploadTerms::VERSION.
--
-- One row per account and terms version (UNIQUE): the acceptance is asked
-- once per account and version, not once per board or per upload. Written
-- by UploadTermsAction (POST /admin/boards/{slug}/assets/terms/accept),
-- read by BoardAssetAction::upload() as the server-side gate (403
-- upload_terms_required without a row for the current version).
--
-- Identity: only the internal pseudonymous user id (no e-mail, no IP, no
-- user agent). board_id is context only ("accepted while on board X") and
-- survives a board deletion as NULL, so the evidence is not lost with a
-- board. The account row is the evidence anchor: account purge cascades.
-- The accepting user row cascades too (conservative: the acceptance then
-- has to be given again by an owner/admin).
CREATE TABLE IF NOT EXISTS upload_terms_consents (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id    BIGINT UNSIGNED NOT NULL,
    board_id      BIGINT UNSIGNED NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    terms_version VARCHAR(32)     NOT NULL,
    accepted_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_upload_terms_consents_account_version (account_id, terms_version),
    CONSTRAINT fk_upload_terms_consents_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_upload_terms_consents_board   FOREIGN KEY (board_id)   REFERENCES boards(id)   ON DELETE SET NULL,
    CONSTRAINT fk_upload_terms_consents_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
