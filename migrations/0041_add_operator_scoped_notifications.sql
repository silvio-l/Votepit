-- 0041_add_operator_scoped_notifications — fifth notification shape:
-- scope='operator', visible to every user with is_operator OR is_support
-- (checked at query time against `users`, not stored per-recipient — same
-- "role, not membership" targeting NotificationRepository::listForUser()
-- already does for 'account' via account_members). Closes a gap: customer
-- support activity (new ticket, customer reply) previously created NO
-- notification at all for operators — only the reverse direction (operator
-- reply -> customer's account) existed (migrations/0024_...). Reuses the
-- existing 'support_reply' type; the recipient side (account vs. operator)
-- is carried by `scope`, not by a second type value.
ALTER TABLE notifications
    DROP CHECK chk_notifications_scope,
    DROP CHECK chk_notifications_scope_account;

ALTER TABLE notifications
    ADD CONSTRAINT chk_notifications_scope CHECK (scope IN ('account', 'broadcast', 'user', 'operator')),
    ADD CONSTRAINT chk_notifications_scope_account CHECK (
        (scope = 'broadcast' AND account_id IS NULL AND user_id IS NULL)
        OR (scope = 'operator' AND account_id IS NULL AND user_id IS NULL)
        OR (scope = 'account' AND account_id IS NOT NULL AND user_id IS NULL)
        OR (scope = 'user' AND account_id IS NOT NULL AND user_id IS NOT NULL)
    );
