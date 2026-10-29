-- 0075_add_weekly_owner_digest — weekly owner digest e-mail
-- (.scratch/competitor-parity issue 14). Three per-user columns on `users`,
-- next to the other notification preferences (0029/0034/0045):
--
--   notify_weekly_digest_email : NULL = "not chosen yet" → the default
--                                applies (ON for accounts the user OWNS,
--                                off for admin-only memberships);
--                                1 = explicitly on (owner AND admin
--                                accounts); 0 = explicitly off (set by the
--                                profile toggle or the one-click
--                                unsubscribe link in every digest mail).
--                                Like every *_email flag it has no effect
--                                until notification_email is confirmed —
--                                the digest is only ever sent to that
--                                confirmed address, never to the
--                                HMAC-pseudonymised identity email.
--   digest_language            : 'de' | 'en' (NULL = en) — the SPA language
--                                the user last saved their notification
--                                preferences in; server-side mail is not
--                                otherwise localised (no per-user language).
--   digest_processed_at        : when the digest job last handled this user
--                                (sent, or skipped because the week was
--                                empty). Drives the weekly cadence and makes
--                                the hourly, budget-limited job resumable
--                                without ever sending twice in one week.
ALTER TABLE users ADD COLUMN notify_weekly_digest_email TINYINT(1) NULL DEFAULT NULL AFTER notify_support_ticket_email;
ALTER TABLE users ADD COLUMN digest_language VARCHAR(2) NULL DEFAULT NULL AFTER notify_weekly_digest_email;
ALTER TABLE users ADD COLUMN digest_processed_at DATETIME NULL DEFAULT NULL AFTER digest_language;
