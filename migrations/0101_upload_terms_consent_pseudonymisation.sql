-- 0101_upload_terms_consent_pseudonymisation — the upload-clause consent proof
-- (migration 0098) outlives an erased account, pseudonymised.
--
-- Before: the proof rows cascaded away with the account and the user. Now an
-- account purge first pseudonymises its proof rows
-- (ExpiredAccountCleaner -> UploadTermsConsentRepository::pseudonymiseForAccount):
-- account_id, board_id and user_id become NULL, the account and board ids are
-- kept only as HMAC-SHA256 pseudonyms (IdentityHasher, server key outside the DB),
-- and pseudonymised_at starts the retention. The cleanup cron deletes such rows
-- once pseudonymised_at is older than Votepit\Domain\UploadTerms::RETENTION_YEARS.
-- Live proof (account still exists) is never deleted by the cron.
--
-- The foreign keys switch from CASCADE to SET NULL for the same reason: deleting
-- a user or an account must not delete the proof, only unlink it.
--
-- ui_language: the UI language the clause was shown in (de|en|fr|es|nl|it), NULL
-- for proof recorded before this migration or without a valid language.
-- Forward-only.
ALTER TABLE upload_terms_consents
    MODIFY account_id BIGINT UNSIGNED NULL,
    MODIFY user_id BIGINT UNSIGNED NULL,
    DROP FOREIGN KEY fk_upload_terms_consents_account,
    DROP FOREIGN KEY fk_upload_terms_consents_user,
    ADD CONSTRAINT fk_upload_terms_consents_account_erased FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_upload_terms_consents_user_erased FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN ui_language VARCHAR(8) NULL AFTER terms_version,
    ADD COLUMN account_pseudonym CHAR(64) NULL AFTER accepted_at,
    ADD COLUMN board_pseudonym CHAR(64) NULL AFTER account_pseudonym,
    ADD COLUMN pseudonymised_at DATETIME NULL AFTER board_pseudonym,
    ADD INDEX idx_upload_terms_consents_pseudonymised (pseudonymised_at);
