<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Update\CommunityEdition;
use Votepit\Update\CommunityReleaseClient;
use Votepit\Update\CommunityStateStore;
use Votepit\Update\UpdateCheckService;
use Votepit\Update\VersionReportService;

/**
 * The daily update check and version report, against an in-memory database
 * and a fake GitHub/Matomo. Covers what is stored, what is sent, and that a
 * failing or malformed answer never changes what is known.
 */
final class CommunityStateTest extends TestCase
{
    private Connection $conn;
    private CommunityStateStore $state;
    private FakeReleaseHttp $http;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->conn->executeStatement('CREATE TABLE app_settings ("key" VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)');
        $this->state = new CommunityStateStore($this->conn);
        $this->http  = new FakeReleaseHttp();
    }

    private function now(string $modifier = ''): \DateTimeImmutable
    {
        $base = new \DateTimeImmutable('2026-10-28 06:31:00', new \DateTimeZone('UTC'));

        return $modifier === '' ? $base : $base->modify($modifier);
    }

    private function publishLatest(string $tag): void
    {
        $this->http->bodies[CommunityEdition::LATEST_RELEASE_URL] = (string) json_encode([
            'tag_name' => $tag,
            'draft' => false,
            'prerelease' => false,
            'assets' => [],
        ]);
    }

    public function test_a_stable_release_is_remembered_with_its_check_time(): void
    {
        $this->publishLatest('v0.1.8');

        $release = (new UpdateCheckService(new CommunityReleaseClient($this->http, 'test'), $this->state))->run($this->now());

        self::assertNotNull($release);
        self::assertSame('0.1.8', $release->version);
        self::assertSame('0.1.8', $this->state->get(CommunityStateStore::LATEST_VERSION));
        self::assertSame('2026-10-28 06:31:00', $this->state->get(CommunityStateStore::CHECKED_AT));
    }

    public function test_a_failed_check_keeps_the_last_known_version(): void
    {
        $this->publishLatest('v0.1.8');
        $service = new UpdateCheckService(new CommunityReleaseClient($this->http, 'test'), $this->state);
        $service->run($this->now());

        $this->http->bodies[CommunityEdition::LATEST_RELEASE_URL] = 'not json';

        self::assertNull($service->run($this->now('+1 day')));
        self::assertSame('0.1.8', $this->state->get(CommunityStateStore::LATEST_VERSION));
    }

    public function test_a_pre_release_never_becomes_the_known_version(): void
    {
        $this->http->bodies[CommunityEdition::LATEST_RELEASE_URL] = (string) json_encode([
            'tag_name' => 'v0.2.0', 'draft' => false, 'prerelease' => true, 'assets' => [],
        ]);

        self::assertNull((new UpdateCheckService(new CommunityReleaseClient($this->http, 'test'), $this->state))->run($this->now()));
        self::assertNull($this->state->get(CommunityStateStore::LATEST_VERSION));
    }

    public function test_the_installation_id_is_random_hex_and_stable(): void
    {
        $id = $this->state->installationId();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        self::assertSame($id, $this->state->installationId());
    }

    public function test_unknown_state_keys_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->state->set('community.something_else', 'x');
    }

    public function test_the_version_report_sends_three_events_and_nothing_personal(): void
    {
        $service = new VersionReportService($this->state, $this->http, '0.1.7');

        self::assertTrue($service->run($this->now(), '8.3.12', 'mariadb 10.11.6'));

        self::assertCount(3, $this->http->requested);
        $cid = $this->state->installationId();
        $joined = implode("\n", $this->http->requested);
        foreach ($this->http->requested as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('11', $query['idsite']);
            self::assertSame($cid, $query['cid']);
            self::assertSame('install', $query['e_c']);
            self::assertSame(CommunityEdition::VERSION_REPORT_PAGE, $query['url']);
            self::assertSame(['idsite', 'rec', 'apiv', 'send_image', 'cid', 'url', 'e_c', 'e_a', 'e_n'], array_keys($query));
        }
        self::assertStringContainsString('e_a=version&e_n=0.1.7', $joined);
        self::assertStringContainsString('e_a=php&e_n=8.3.12', $joined);
        self::assertStringContainsString('e_a=database&e_n=mariadb+10.11.6', $joined);
        self::assertStringNotContainsString('@', $joined);
    }

    public function test_the_version_report_is_sent_once_per_utc_day(): void
    {
        $service = new VersionReportService($this->state, $this->http, '0.1.7');

        $service->run($this->now(), '8.3.12', 'mariadb 10.11.6');
        $service->run($this->now('+5 hours'), '8.3.12', 'mariadb 10.11.6');
        self::assertSame(3, $this->sentCount());

        $service->run($this->now('+1 day'), '8.3.12', 'mariadb 10.11.6');
        self::assertSame(6, $this->sentCount());
    }

    private function sentCount(): int
    {
        return count($this->http->requested);
    }
}
