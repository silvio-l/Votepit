<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Votepit\Support\Clock;

/** Test clock pinned to one UTC instant; movable between requests. */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $utc)
    {
        $this->set($utc);
    }

    public function set(string $utc): void
    {
        $this->now = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
