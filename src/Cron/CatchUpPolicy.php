<?php

declare(strict_types=1);

namespace Votepit\Cron;

/**
 * What the cron manager does with scheduled slots it missed (outage, a
 * manager that did not tick, a lease still held by a previous run).
 *
 *  - None:   only a slot that is still fresh (within the tick window) runs.
 *            An older unclaimed slot is skipped and the gap is logged. Right
 *            for hourly jobs whose next slot makes the skipped one pointless
 *            (demo reset, digests, health checks).
 *  - Latest: the latest missed slot runs once, late. Right for cleanups that
 *            must eventually happen.
 *
 * Earlier missed slots are never replayed, under either policy.
 */
enum CatchUpPolicy: string
{
    case None   = 'none';
    case Latest = 'latest';
}
