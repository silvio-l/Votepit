-- 0105_create_participant_mail_preferences — per-category cadence for
-- participant mails (close-the-loop + re-engagement decision 2: each person
-- chooses instant / daily / weekly / off per category).
--
-- Categories:
--   my_ideas      mails about ideas the user wrote (status, comments)
--   participated  mails about ideas the user voted on, commented on or follows
--   new_ideas     opt-in digest of new ideas on boards the user took part in
--                 (weekly or off only; default off)
-- A missing row means the default: my_ideas = instant, participated = daily,
-- new_ideas = off (see ParticipantMailCategory::defaultCadence()).
--
-- Backfill: existing users keep today's behaviour. Before this migration
-- status/comment mails were sent only when the legacy per-event e-mail flags
-- were on, so those users start with my_ideas = instant and participated =
-- daily when a flag was on, and off otherwise. Guests (no account) have no
-- rows at all.
CREATE TABLE IF NOT EXISTS participant_mail_preferences (
    user_id    BIGINT UNSIGNED NOT NULL,
    category   VARCHAR(16)     NOT NULL,
    cadence    VARCHAR(8)      NOT NULL,
    updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, category),
    CONSTRAINT fk_pmp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_pmp_category CHECK (category IN ('my_ideas', 'participated', 'new_ideas')),
    CONSTRAINT chk_pmp_cadence CHECK (cadence IN ('instant', 'daily', 'weekly', 'off'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO participant_mail_preferences (user_id, category, cadence)
SELECT u.id, 'my_ideas', CASE WHEN u.notify_idea_status_email = 1 OR u.notify_idea_comment_email = 1 THEN 'instant' ELSE 'off' END
FROM users u
WHERE u.is_guest_placeholder = 0;

INSERT INTO participant_mail_preferences (user_id, category, cadence)
SELECT u.id, 'participated', CASE WHEN u.notify_idea_status_email = 1 OR u.notify_thread_reply_email = 1 THEN 'daily' ELSE 'off' END
FROM users u
WHERE u.is_guest_placeholder = 0;
