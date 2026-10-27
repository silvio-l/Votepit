-- 0090_add_cron_jobs — lease row per cron job (central cron manager,
-- see Votepit\Cron\CronJobRunner / CronJobRepository and the scheduling
-- research in .scratch/cron-manager/research.md §3.2–3.3).
--
-- One row per registered job, upserted by the manager on every tick from
-- the job's definition (cron expression in UTC, catch-up policy, runtime
-- budget, source). The lease columns make a claim atomic:
--
--   last_slot        the scheduled occurrence (UTC) last claimed. A slot runs
--                    at most once, even when two managers tick concurrently.
--   lease_token      fencing token of the current holder (NULL = free). Only
--                    the holder of this exact token may finish the run, so a
--                    stale holder whose lease was reclaimed cannot overwrite
--                    the new holder's result.
--   lease_expires_at UTC. Lease = the job's runtime budget + a margin, so the
--                    job's own timeout fires before anyone else may reclaim.
--
-- consecutive_failures / alert_state / last_alerted_at feed the self-alert
-- mail (threshold, re-alert throttle, one recovery notice). Job output is
-- never stored here (it can contain personal data), only exit codes.
--
-- Forward-only. cron_manager_runs (0065) and cron_heartbeats (0048) stay as
-- they are; this table replaces the due-check bookkeeping of 0065.
CREATE TABLE IF NOT EXISTS cron_jobs (
    job_name             VARCHAR(64)  NOT NULL,
    source               VARCHAR(32)  NOT NULL,
    cron_expr            VARCHAR(64)  NOT NULL,
    catch_up             VARCHAR(8)   NOT NULL DEFAULT 'none',
    max_runtime_s        INT UNSIGNED NOT NULL,
    last_slot            DATETIME     NULL,
    lease_token          CHAR(32)     NULL,
    lease_expires_at     DATETIME     NULL,
    pid                  INT UNSIGNED NULL,
    started_at           DATETIME     NULL,
    finished_at          DATETIME     NULL,
    last_exit            INT          NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    alert_state          VARCHAR(16)  NOT NULL DEFAULT 'ok',
    last_alerted_at      DATETIME     NULL,
    PRIMARY KEY (job_name),
    KEY idx_cron_jobs_lease_expires_at (lease_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
