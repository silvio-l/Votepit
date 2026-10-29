<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * Reads release metadata from the GitHub REST API. Drafts and pre-releases are
 * never returned; any malformed or unexpected answer is "no answer" (null), so
 * a bad response can never produce a bogus update banner or mail.
 */
final readonly class CommunityReleaseClient
{
    public function __construct(
        private ReleaseHttp $http,
        private string $userAgent,
    ) {}

    public function latest(): ?CommunityRelease
    {
        return $this->fetch(CommunityEdition::LATEST_RELEASE_URL);
    }

    public function byVersion(string $version): ?CommunityRelease
    {
        if (!SemanticVersion::isValid($version)) {
            return null;
        }

        return $this->fetch(sprintf(CommunityEdition::RELEASE_BY_TAG_URL, 'v' . $version));
    }

    private function fetch(string $url): ?CommunityRelease
    {
        $body = $this->http->get(
            $url,
            ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: ' . $this->userAgent],
            CommunityEdition::HTTP_TIMEOUT_SECONDS,
        );

        return $body === null ? null : self::parse($body);
    }

    /** Parses one GitHub release object. Public for tests. */
    public static function parse(string $json): ?CommunityRelease
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['draft'] ?? true) !== false || ($data['prerelease'] ?? true) !== false) {
            return null;
        }

        $tag = $data['tag_name'] ?? null;
        if (!is_string($tag)) {
            return null;
        }

        $version = SemanticVersion::fromTag($tag);
        if ($version === null) {
            return null;
        }

        $assetPrefix = 'https://github.com/' . CommunityEdition::GITHUB_REPOSITORY . '/releases/download/';
        $assets      = [];
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = $asset['name'] ?? null;
            $url  = $asset['browser_download_url'] ?? null;
            if (is_string($name) && is_string($url) && str_starts_with($url, $assetPrefix)) {
                $assets[$name] = $url;
            }
        }

        return new CommunityRelease(
            $version,
            sprintf(CommunityEdition::RELEASE_PAGE_URL, $tag),
            $assets,
        );
    }
}
