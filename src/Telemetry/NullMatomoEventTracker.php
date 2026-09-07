<?php

declare(strict_types=1);

namespace Votepit\Telemetry;

/** No matomo_url/matomo_site_id configured — every call is a no-op. */
final class NullMatomoEventTracker implements MatomoEventTracker
{
    public function track(string $category, string $action): void {}
}
