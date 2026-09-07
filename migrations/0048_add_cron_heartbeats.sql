-- 0048_add_cron_heartbeats — dead-man's-switch table (deep-review-2026-09
-- finding e): the GDPR cleanup crons (bin/cleanup-expired-accounts.php,
-- bin/cleanup-rate-limits.php) previously had no way to notice if they
-- silently stopped running (a broken/removed crontab entry, a hoster outage)
-- other than someone eventually spotting unbounded table growth or overdue
-- account deletions. Each cron now upserts its own row on every run
-- (success or failure); bin/check-cron-heartbeats.php (a separate, small,
-- independently-scheduled watchdog cron) reports to Sentry + a non-zero
-- exit code if a job's last run is older than its expected interval.
CREATE TABLE IF NOT EXISTS cron_heartbeats (
    job_name    VARCHAR(64)  NOT NULL,
    last_run_at DATETIME     NOT NULL,
    status      VARCHAR(16)  NOT NULL,
    detail      VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
