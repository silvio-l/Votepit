<?php

declare(strict_types=1);

namespace Votepit\Support;

/** The real wall clock, always in UTC (independent of date.timezone). */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
