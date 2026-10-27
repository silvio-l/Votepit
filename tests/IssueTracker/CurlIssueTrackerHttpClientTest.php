<?php

declare(strict_types=1);

namespace Votepit\Tests\IssueTracker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\IssueTracker\CurlIssueTrackerHttpClient;
use Votepit\IssueTracker\IssueTrackerHttpException;
use Votepit\IssueTracker\IssueTrackerProvider;
use Votepit\Security\Webhook\WebhookUrlGuard;

/**
 * SSRF guard of the real client — every case here must be refused BEFORE
 * any connection attempt (no network needed).
 */
final class CurlIssueTrackerHttpClientTest extends TestCase
{
    /** @return iterable<string, array{IssueTrackerProvider, string}> */
    public static function refusedUrls(): iterable
    {
        yield 'foreign host'              => [IssueTrackerProvider::GitHub, 'https://evil.example/repos/a/b'];
        yield 'other provider host'       => [IssueTrackerProvider::Linear, 'https://api.github.com/repos/a/b'];
        yield 'http'                      => [IssueTrackerProvider::Linear, 'http://api.linear.app/graphql'];
        yield 'explicit port'             => [IssueTrackerProvider::Linear, 'https://api.linear.app:8443/graphql'];
        yield 'userinfo'                  => [IssueTrackerProvider::GitHub, 'https://x@api.github.com/repos/a/b'];
        yield 'other github endpoint'     => [IssueTrackerProvider::GitHub, 'https://api.github.com/user'];
        yield 'dot segment'               => [IssueTrackerProvider::GitHub, 'https://api.github.com/repos/a/../user'];
        yield 'lookalike host'            => [IssueTrackerProvider::Linear, 'https://api.linear.app.evil.example/graphql'];
    }

    #[DataProvider('refusedUrls')]
    public function test_refuses_urls_outside_the_fixed_api_host(IssueTrackerProvider $provider, string $url): void
    {
        $client = new CurlIssueTrackerHttpClient(new WebhookUrlGuard(static fn (string $host): array => ['140.82.112.6']));

        $this->expectException(IssueTrackerHttpException::class);
        $client->send($provider, 'GET', $url, ['Authorization: Bearer secret']);
    }

    public function test_refuses_an_api_host_resolving_to_a_private_address(): void
    {
        $client = new CurlIssueTrackerHttpClient(new WebhookUrlGuard(static fn (string $host): array => ['10.0.0.5']));

        try {
            $client->send(IssueTrackerProvider::GitHub, 'GET', 'https://api.github.com/repos/acme/roadmap', ['Authorization: Bearer secret']);
            self::fail('expected refusal');
        } catch (IssueTrackerHttpException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }
}
