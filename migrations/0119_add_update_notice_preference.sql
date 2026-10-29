-- 0119_add_update_notice_preference — one more operator-nudge switch: the
-- "a newer Community release is available" e-mail (Votepit\Nudge\OperatorNudgeKind::UpdateAvailable).
--
-- Default ON, like the other operator nudges: the mail goes only to the owner
-- of the installation, once per release (operator_nudge_log, scope 'version:<x.y.z>'),
-- and carries its own one-click unsubscribe (Votepit\Security\OperatorNudgeUnsubscribeToken).
--
-- The update check's own state (latest seen version, check time, installation ID,
-- day of the last version report) lives in the existing app_settings table under
-- the community.* keys (Votepit\Update\CommunityStateStore), so this migration adds
-- only the opt-out switch.

ALTER TABLE users ADD COLUMN notify_update_available_email TINYINT(1) NOT NULL DEFAULT 1;
