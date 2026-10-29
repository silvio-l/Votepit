<?php

declare(strict_types=1);

namespace Votepit\Update;

/** One published stable Community release as the update channel describes it. */
final readonly class CommunityRelease
{
    /** @param array<string, string> $assets asset file name => https download URL */
    public function __construct(
        public string $version,
        public string $pageUrl,
        public array $assets,
    ) {}

    public function assetUrl(string $name): ?string
    {
        return $this->assets[$name] ?? null;
    }
}
