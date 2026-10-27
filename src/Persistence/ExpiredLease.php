<?php

declare(strict_types=1);

namespace Votepit\Persistence;

/** A lease whose holder outlived it (see CronJobRepository::expiredLeases()). */
final readonly class ExpiredLease
{
    public function __construct(
        public string $jobName,
        public string $leaseToken,
        public ?int $pid,
    ) {}
}
