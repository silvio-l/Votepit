-- 0111_add_operator_nudge_preferences — one opt-out switch per operator nudge
-- kind (onboarding tips, quiet-board tips, plan-limit heads-up). Default ON:
-- these mails only ever go to the owner of the account they are about, and
-- every mail carries a one-click unsubscribe for its own kind
-- (Votepit\Security\OperatorNudgeUnsubscribeToken).

ALTER TABLE users ADD COLUMN notify_onboarding_nudge_email TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN notify_quiet_board_email      TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN notify_plan_limit_email       TINYINT(1) NOT NULL DEFAULT 1;
