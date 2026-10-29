-- 0065_add_cron_manager_runs — dispatch bookkeeping for the cron manager
-- (core/bin/run-due-crons.php, cloud/bin/run-due-crons.php): a single
-- scheduled task per environment that decides, on every tick, which of the
-- registered per-job scripts are due (elapsed time since their last
-- dispatch >= their configured interval) and launches each due job as its
-- own isolated subprocess — replacing one Plesk Scheduled Task per script
-- with one per environment.
--
-- Deliberately separate from cron_heartbeats (migrations/0048): that table
-- records a job's own SUCCESS/FAILURE outcome (written by the job itself,
-- only reached if the job got far enough to run); this table records the
-- MANAGER's dispatch attempt (written by the manager right before it
-- launches the subprocess, regardless of outcome) so due-checking works
-- identically for every registered job, including ones that don't record a
-- heartbeat themselves (e.g. qualify-referrals.php), and so a job that
-- crashes before reaching its own heartbeat call still can't be dispatched
-- twice within its interval.
CREATE TABLE IF NOT EXISTS cron_manager_runs (
    job_name          VARCHAR(64) NOT NULL,
    last_dispatched_at DATETIME   NOT NULL,
    PRIMARY KEY (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
