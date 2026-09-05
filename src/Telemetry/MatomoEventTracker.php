<?php

declare(strict_types=1);

namespace Votepit\Telemetry;

/**
 * Server-side counterpart to core/app/src/lib/analytics.ts's `trackEvent` —
 * for the small set of goal events that are only ever confirmed server-side
 * (e.g. a payment webhook), never client-visible at the moment they become
 * true. Same contract as the JS version: a small, fixed set of low-cardinality
 * goal events, never PII, never blocking/throwing into the caller.
 */
interface MatomoEventTracker
{
    public function track(string $category, string $action): void;
}
