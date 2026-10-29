-- 0108_create_participant_guest_suppressions — one-click unsubscribe for
-- guests with a stored anonymous-vote notification e-mail (re-engagement
-- decision 3). A guest has no account to hold a preference, so the signed
-- unsubscribe link writes the keyed HMAC of the address here; the digest and
-- the instant path both check it before they queue or send anything.
CREATE TABLE IF NOT EXISTS participant_guest_suppressions (
    recipient_key CHAR(64) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (recipient_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
