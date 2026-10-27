-- 0069_replace_voter_email_hash_with_encryption — ADR 0004 correction
-- (post-implementation review): votes.voter_email_hash
-- (migrations/0068_add_anonymous_voting.sql) stored the optional
-- notification email as an IRREVERSIBLE HMAC. That made it impossible to
-- ever actually send the status-change notification the voting UI
-- promises for that field — collecting PII for a purpose it can
-- structurally never fulfil is a data-minimization violation (GDPR Art. 5
-- Abs. 1 lit. c), not an acceptable "deferred feature".
--
-- Replaced with voter_email_enc: reversibly encrypted at rest via
-- EncryptionService (context 'anon_vote_email' — domain-separated from
-- every other at-rest secret under the same app_key, same pattern already
-- used for abuse_reports.reporter_email_enc, see migrations/
-- 0013_add_operator_panel.sql and AbuseReportRepository's class doc). The
-- ciphertext is decrypted only when IdeaStatusAction actually sends the
-- promised status-change email (see its class doc).
ALTER TABLE votes DROP COLUMN voter_email_hash;
ALTER TABLE votes ADD COLUMN voter_email_enc TEXT NULL AFTER voter_token_hash;
