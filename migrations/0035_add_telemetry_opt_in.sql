-- 0035_add_telemetry_opt_in — self-host product-improvement telemetry (opt-out).
--
-- accounts.telemetry_opted_in: 1 (default) — anonymous, aggregate product
-- telemetry (install/feature-usage counters, never board/user content) is ON
-- by default, matching this project's portfolio-wide Matomo practice
-- (cookieless + IP-2-byte-anonymized, legitimate interest per Art. 6(1)(f)
-- GDPR / no consent required for cookieless tracking under §25(1) TDDDG —
-- see ~/Documents/Projekte/matomo/docs/privacy-dsgvo.md). The Setup Wizard
-- surfaces this plainly with an equally-easy toggle (Art. 21 GDPR right to
-- object, same "opt-out" shape as this project's existing Matomo OptOutJS
-- embeds) rather than blocking the wizard on an accept/decline choice.
-- accounts.telemetry_decided_at: NULL until the operator has touched the
-- toggle at least once (purely informational — never gates anything).
-- Existing accounts (self-host installs upgrading into this migration) are
-- backfilled to the same default-on state as a fresh install.
ALTER TABLE accounts ADD COLUMN telemetry_opted_in TINYINT(1) NOT NULL DEFAULT 1 AFTER onboarding_completed_at;
ALTER TABLE accounts ADD COLUMN telemetry_decided_at DATETIME NULL AFTER telemetry_opted_in;
