-- 0107_create_participant_digest_sends — idempotency claims for participant
-- and new-idea digests (close-the-loop slice 03). One row per recipient and
-- cadence period (e.g. "u:42" + "daily:2026-10-26"). The job inserts the claim
-- before it sends and deletes it again when the send fails, so a retry can
-- go out while a second run in the same period never does. The key holds no
-- address; rows are purged after 120 days.
CREATE TABLE IF NOT EXISTS participant_digest_sends (
    recipient_key VARCHAR(80) NOT NULL,
    period_key    VARCHAR(40) NOT NULL,
    sent_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (recipient_key, period_key),
    KEY idx_pds_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
