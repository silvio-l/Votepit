<?php

declare(strict_types=1);

namespace Votepit\Nudge;

/** Outcome of one nudge run (or one dispatch inside it); printed and heartbeat-recorded by the cron entry point. */
final readonly class NudgeRunResult
{
    public function __construct(
        public int $sent = 0,
        public int $failed = 0,
        /** True when the per-run budget or the shared mail-volume headroom stopped the run early. */
        public bool $budgetExhausted = false,
        public ?string $lastError = null,
    ) {}

    /** Mails attempted (sent + failed) — what the per-run budget counts. */
    public function attempts(): int
    {
        return $this->sent + $this->failed;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->sent + $other->sent,
            $this->failed + $other->failed,
            $this->budgetExhausted || $other->budgetExhausted,
            $other->lastError ?? $this->lastError,
        );
    }
}
