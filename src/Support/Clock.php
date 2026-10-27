<?php

declare(strict_types=1);

namespace Votepit\Support;

/**
 * Injectable "now" for server-authoritative time decisions (board phase
 * windows, broaden-use-cases issue 04). Production uses SystemClock; tests
 * inject a fixed clock to pin exact boundaries.
 */
interface Clock
{
    /** The current instant, in UTC. */
    public function now(): \DateTimeImmutable;
}
