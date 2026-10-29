<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use PHPUnit\Framework\TestCase;
use Votepit\Update\CommunityReleaseClient;

final class CommunityReleaseClientTest extends TestCase
{
    private const BASE = 'https://github.com/silvio-l/Votepit/releases/download/v0.1.8/';

    /** @param array<string, mixed> $overrides */
    private function release(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'tag_name'   => 'v0.1.8',
            'draft'      => false,
            'prerelease' => false,
            'assets'     => [
                ['name' => 'votepit-community-0.1.8.zip', 'browser_download_url' => self::BASE . 'votepit-community-0.1.8.zip'],
                ['name' => 'votepit-community-0.1.8.zip.sha256', 'browser_download_url' => self::BASE . 'votepit-community-0.1.8.zip.sha256'],
            ],
        ], $overrides));
    }

    public function test_a_published_stable_release_is_parsed(): void
    {
        $release = CommunityReleaseClient::parse($this->release());

        self::assertNotNull($release);
        self::assertSame('0.1.8', $release->version);
        self::assertSame('https://github.com/silvio-l/Votepit/releases/tag/v0.1.8', $release->pageUrl);
        self::assertSame(self::BASE . 'votepit-community-0.1.8.zip', $release->assetUrl('votepit-community-0.1.8.zip'));
        self::assertNull($release->assetUrl('missing.zip'));
    }

    public function test_drafts_pre_releases_and_bad_tags_are_never_offered(): void
    {
        self::assertNull(CommunityReleaseClient::parse($this->release(['draft' => true])));
        self::assertNull(CommunityReleaseClient::parse($this->release(['prerelease' => true])));
        self::assertNull(CommunityReleaseClient::parse($this->release(['tag_name' => 'v0.1.8-rc1'])));
        self::assertNull(CommunityReleaseClient::parse('not json'));
        self::assertNull(CommunityReleaseClient::parse('[]'));
    }

    public function test_assets_from_other_hosts_are_ignored(): void
    {
        $release = CommunityReleaseClient::parse($this->release(['assets' => [
            ['name' => 'votepit-community-0.1.8.zip', 'browser_download_url' => 'https://evil.example.com/votepit-community-0.1.8.zip'],
        ]]));

        self::assertNotNull($release);
        self::assertNull($release->assetUrl('votepit-community-0.1.8.zip'));
    }
}
