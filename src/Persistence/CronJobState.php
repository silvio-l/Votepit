<?php

declare(strict_types=1);

namespace Votepit\Persistence;

/** One cron_jobs row, read back (see migrations/0090_add_cron_jobs.sql). Times are UTC. */
final readonly class CronJobState
{
    public function __construct(
        public string $jobName,
        public ?\DateTimeImmutable $lastSlot,
        public ?string $leaseToken,
        public ?\DateTimeImmutable $leaseExpiresAt,
        public ?int $pid,
        public ?\DateTimeImmutable $finishedAt,
        public ?int $lastExit,
        public int $consecutiveFailures,
        public string $alertState,
        public ?\DateTimeImmutable $lastAlertedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            jobName: (string) $row['job_name'],
            lastSlot: self::utc($row['last_slot']),
            leaseToken: $row['lease_token'] === null ? null : (string) $row['lease_token'],
            leaseExpiresAt: self::utc($row['lease_expires_at']),
            pid: $row['pid'] === null ? null : (int) $row['pid'],
            finishedAt: self::utc($row['finished_at']),
            lastExit: $row['last_exit'] === null ? null : (int) $row['last_exit'],
            consecutiveFailures: (int) $row['consecutive_failures'],
            alertState: (string) $row['alert_state'],
            lastAlertedAt: self::utc($row['last_alerted_at']),
        );
    }

    private static function utc(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
