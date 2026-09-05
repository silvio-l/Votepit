-- 0038_finalize_user_public_id_column — closes out the public_id backfill.
-- Makes public_id NOT NULL + UNIQUE now that every row has one (0037).
--
-- Fail-secure by design: if the MODIFY line fails (NOT-NULL constraint
-- violation), 0037_backfill_user_public_id was incomplete. That is
-- intentional and not caught here — no silent data loss, see
-- migrations/README.md + CLAUDE.md (mandatory backup immediately before
-- every migration remains the safety net). Also not atomic across
-- statements (DDL implicitly commits per statement, same accepted
-- boundary as 0006_finalize_email_hmac_column.sql).
ALTER TABLE users MODIFY public_id CHAR(10) NOT NULL;
ALTER TABLE users ADD UNIQUE KEY uq_users_public_id (public_id);
