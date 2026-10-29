<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * The daily update check: asks GitHub for the latest stable release and
 * remembers it. A failed or malformed answer changes nothing, so the last
 * known version stays visible and nothing is ever downgraded by a bad response.
 *
 * Callers decide what a newer version means (banner, operator mail); this
 * service only records what GitHub said.
 */
final readonly class UpdateCheckService
{
    public function __construct(
        private CommunityReleaseClient $releases,
        private CommunityStateStore $state,
    ) {}

    /** @return CommunityRelease|null the release GitHub reported, or null when there was no usable answer */
    public function run(\DateTimeImmutable $now): ?CommunityRelease
    {
        $release = $this->releases->latest();
        if (!$release instanceof \Votepit\Update\CommunityRelease) {
            return null;
        }

        $this->state->set(CommunityStateStore::LATEST_VERSION, $release->version);
        $this->state->set(
            CommunityStateStore::CHECKED_AT,
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );

        return $release;
    }
}
