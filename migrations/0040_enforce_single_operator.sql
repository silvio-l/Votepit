-- 0040_enforce_single_operator — DB-level guarantee that at most one user
-- can ever hold is_operator = 1 at a time (the platform's single top
-- authority — see AuthZMiddleware::operator() class doc: strictly above
-- account owner/admin, and NOT the same thing as a self-hosted install's
-- informal "operator" of their own instance, which has no such flag/
-- singleton concept and stays governed only by admin_emails/is_admin).
--
-- MySQL has no partial/filtered unique index, so this uses the standard
-- workaround: a generated column that is NULL unless is_operator = 1, with
-- a UNIQUE index on it. MySQL unique indexes allow unlimited NULLs but at
-- most one non-NULL value — so a second UPDATE ... SET is_operator = 1
-- fails with a unique-constraint violation instead of silently creating a
-- second operator. Fail-secure by construction, not just an app-level
-- convention (there is no HTTP path to set is_operator at all — see
-- UserRepository's class doc — this closes the same gap for whoever runs
-- the direct SQL/bin/grant-operator.php by hand).
ALTER TABLE users ADD COLUMN operator_singleton TINYINT(1)
    GENERATED ALWAYS AS (CASE WHEN is_operator = 1 THEN 1 ELSE NULL END) STORED;
ALTER TABLE users ADD UNIQUE KEY uq_users_operator_singleton (operator_singleton);
