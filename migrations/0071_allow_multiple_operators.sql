-- 0071_allow_multiple_operators — lifts the single-operator constraint from
-- 0040_enforce_single_operator so a trusted second person can hold
-- is_operator = 1 as a backup (e.g. for locking boards that violate the
-- terms). No code path relied on there being only one operator; every check
-- is "is this user an operator" / "does this account have an operator
-- member". Granting stays CLI-only (bin/grant-operator.php, direct DB
-- access) — there is still no HTTP path that sets is_operator.
ALTER TABLE users DROP INDEX uq_users_operator_singleton;
ALTER TABLE users DROP COLUMN operator_singleton;
