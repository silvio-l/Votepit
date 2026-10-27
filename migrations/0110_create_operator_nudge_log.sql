-- 0110_create_operator_nudge_log — sent-log of the operator nudges
-- (onboarding, quiet board, plan-limit heads-up; see Votepit\Nudge\*).
--
-- One row = one nudge that was claimed for one recipient. The UNIQUE key is
-- the idempotency guard: a claim is an INSERT, a second attempt for the same
-- (user, account, kind, scope) fails and is skipped, so a nudge is never sent
-- twice. A failed send deletes its claim again, so the next run retries it.
--
-- scope names the instance of a kind: 'day3' / 'day7' (onboarding), 'board:<id>'
-- (quiet board), 'limit:boards' / 'limit:members' (plan limit). Rows of the
-- quiet-board and plan-limit kinds are deleted again as soon as the condition
-- clears (activity returns, usage drops below 80 %), which re-arms the nudge.
--
-- Retention: rows follow the account and the user (ON DELETE CASCADE); the
-- onboarding rows are purged by the job after 30 days (they only matter for the
-- 14-day onboarding window).

CREATE TABLE IF NOT EXISTS operator_nudge_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    kind       VARCHAR(32)     NOT NULL,
    scope      VARCHAR(64)     NOT NULL,
    sent_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_operator_nudge_log (user_id, account_id, kind, scope),
    KEY idx_operator_nudge_log_account (account_id, kind, scope),
    KEY idx_operator_nudge_log_sent (kind, sent_at),
    CONSTRAINT fk_operator_nudge_log_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_operator_nudge_log_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
