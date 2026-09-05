<?php

declare(strict_types=1);

namespace Votepit\Telemetry;

/** In-memory tracker for tests — records calls instead of making an HTTP request. */
final class InMemoryMatomoEventTracker implements MatomoEventTracker
{
    /** @var list<array{category: string, action: string}> */
    public array $tracked = [];

    public function track(string $category, string $action): void
    {
        $this->tracked[] = ['category' => $category, 'action' => $action];
    }
}
