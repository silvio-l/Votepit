<?php

declare(strict_types=1);

namespace Votepit\Digest;

/** Outcome of one WeeklyDigestService::run() (printed + heartbeat-recorded by bin/send-weekly-digest.php). */
final readonly class DigestRunResult
{
    public function __construct(
        public int $sent,
        public int $skippedEmpty,
        public int $failed,
        /** True when the per-run budget or the shared mail-volume headroom stopped the run early. */
        public bool $budgetExhausted,
        public ?string $lastError = null,
    ) {}
}
