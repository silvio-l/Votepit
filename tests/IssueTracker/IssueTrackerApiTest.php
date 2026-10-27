<?php

declare(strict_types=1);

namespace Votepit\Tests\IssueTracker;

use PHPUnit\Framework\TestCase;
use Votepit\IssueTracker\InMemoryIssueTrackerHttpClient;
use Votepit\IssueTracker\IssueTrackerApi;
use Votepit\IssueTracker\IssueTrackerException;
use Votepit\IssueTracker\IssueTrackerProvider;

final class IssueTrackerApiTest extends TestCase
{
    private const LINEAR = 'https://api.linear.app/graphql';

    private InMemoryIssueTrackerHttpClient $http;
    private IssueTrackerApi $api;

    protected function setUp(): void
    {
        $this->http = new InMemoryIssueTrackerHttpClient();
        $this->api  = new IssueTrackerApi($this->http);
    }

    private function expectKey(string $key, callable $call): void
    {
        try {
            $call();
            self::fail('expected IssueTrackerException ' . $key);
        } catch (IssueTrackerException $e) {
            self::assertSame($key, $e->key);
        }
    }

    public function test_linear_verify_resolves_the_team_and_sends_the_key_only_to_linear(): void
    {
        $this->http->queue('POST', self::LINEAR, 200, ['data' => ['teams' => ['nodes' => [['id' => 'team-uuid', 'key' => 'ENG']]]]]);

        self::assertSame(['target' => 'ENG', 'target_id' => 'team-uuid'], $this->api->verify(IssueTrackerProvider::Linear, 'lin_api_secret', 'eng'));

        $request = $this->http->requests[0];
        self::assertSame(self::LINEAR, $request['url']);
        self::assertContains('Authorization: lin_api_secret', $request['headers']);
        self::assertSame('ENG', $request['json']['variables']['key'] ?? null);
    }

    public function test_linear_verify_error_mapping(): void
    {
        $this->http->queue('POST', self::LINEAR, 200, ['data' => ['teams' => ['nodes' => []]]]);
        $this->expectKey(IssueTrackerException::TARGET_NOT_FOUND, fn (): array => $this->api->verify(IssueTrackerProvider::Linear, 't', 'ENG'));

        $this->http->queue('POST', self::LINEAR, 400, ['errors' => [['message' => 'auth', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]]);
        $this->expectKey(IssueTrackerException::INVALID_CREDENTIALS, fn (): array => $this->api->verify(IssueTrackerProvider::Linear, 't', 'ENG'));

        $this->http->queue('POST', self::LINEAR, 503, []);
        $this->expectKey(IssueTrackerException::UNAVAILABLE, fn (): array => $this->api->verify(IssueTrackerProvider::Linear, 't', 'ENG'));

        $this->http->queueFailure('POST', self::LINEAR);
        $this->expectKey(IssueTrackerException::UNAVAILABLE, fn (): array => $this->api->verify(IssueTrackerProvider::Linear, 't', 'ENG'));
    }

    public function test_github_verify_uses_the_canonical_full_name(): void
    {
        $this->http->queue('GET', 'https://api.github.com/repos/acme/roadmap', 200, ['full_name' => 'Acme/Roadmap', 'has_issues' => true]);

        self::assertSame(['target' => 'Acme/Roadmap', 'target_id' => ''], $this->api->verify(IssueTrackerProvider::GitHub, 'github_pat_x', 'acme/roadmap'));
        self::assertContains('Authorization: Bearer github_pat_x', $this->http->requests[0]['headers']);
    }

    public function test_github_verify_error_mapping(): void
    {
        $url = 'https://api.github.com/repos/acme/roadmap';
        $this->http->queue('GET', $url, 401, []);
        $this->expectKey(IssueTrackerException::INVALID_CREDENTIALS, fn (): array => $this->api->verify(IssueTrackerProvider::GitHub, 't', 'acme/roadmap'));
        $this->http->queue('GET', $url, 404, []);
        $this->expectKey(IssueTrackerException::TARGET_NOT_FOUND, fn (): array => $this->api->verify(IssueTrackerProvider::GitHub, 't', 'acme/roadmap'));
        $this->http->queue('GET', $url, 200, ['full_name' => 'acme/roadmap', 'has_issues' => false]);
        $this->expectKey(IssueTrackerException::TARGET_NOT_FOUND, fn (): array => $this->api->verify(IssueTrackerProvider::GitHub, 't', 'acme/roadmap'));
        $this->http->queue('GET', $url, 500, []);
        $this->expectKey(IssueTrackerException::UNAVAILABLE, fn (): array => $this->api->verify(IssueTrackerProvider::GitHub, 't', 'acme/roadmap'));
    }

    public function test_github_dot_segment_repo_never_reaches_the_network(): void
    {
        $this->expectKey(IssueTrackerException::UNAVAILABLE, fn (): array => $this->api->verify(IssueTrackerProvider::GitHub, 't', 'acme/..'));
        self::assertSame([], $this->http->requests);
    }

    public function test_linear_create_issue(): void
    {
        $this->http->queue('POST', self::LINEAR, 200, ['data' => ['issueCreate' => ['success' => true, 'issue' => [
            'id' => 'issue-uuid', 'identifier' => 'ENG-42', 'url' => 'https://linear.app/acme/issue/ENG-42/dark-mode',
        ]]]]);

        $created = $this->api->createIssue(IssueTrackerProvider::Linear, 'tok', 'ENG', 'team-uuid', 'Dark mode', 'Body');

        self::assertSame(['external_id' => 'issue-uuid', 'key' => 'ENG-42', 'url' => 'https://linear.app/acme/issue/ENG-42/dark-mode'], $created);
        self::assertSame(['teamId' => 'team-uuid', 'title' => 'Dark mode', 'description' => 'Body'], $this->http->requests[0]['json']['variables']['input'] ?? null);
    }

    public function test_github_create_issue(): void
    {
        $this->http->queue('POST', 'https://api.github.com/repos/acme/roadmap/issues', 201, [
            'id' => 555, 'number' => 7, 'html_url' => 'https://github.com/acme/roadmap/issues/7',
        ]);

        $created = $this->api->createIssue(IssueTrackerProvider::GitHub, 'tok', 'acme/roadmap', '', 'Dark mode', 'Body');

        self::assertSame(['external_id' => '555', 'key' => '#7', 'url' => 'https://github.com/acme/roadmap/issues/7'], $created);
    }

    /** A provider answer with an active/foreign link is never accepted. */
    public function test_create_issue_rejects_foreign_or_active_issue_urls(): void
    {
        foreach (['javascript:alert(1)', 'https://evil.example/acme/roadmap/issues/7', 'http://github.com/acme/roadmap/issues/7'] as $url) {
            $this->http->queue('POST', 'https://api.github.com/repos/acme/roadmap/issues', 201, ['id' => 555, 'number' => 7, 'html_url' => $url]);
            $this->expectKey(IssueTrackerException::UNAVAILABLE, fn (): array => $this->api->createIssue(IssueTrackerProvider::GitHub, 'tok', 'acme/roadmap', '', 'T', 'B'));
        }
    }

    public function test_create_issue_error_mapping(): void
    {
        $url = 'https://api.github.com/repos/acme/roadmap/issues';
        $this->http->queue('POST', $url, 401, []);
        $this->expectKey(IssueTrackerException::INVALID_CREDENTIALS, fn (): array => $this->api->createIssue(IssueTrackerProvider::GitHub, 't', 'acme/roadmap', '', 'T', 'B'));
        $this->http->queue('POST', $url, 403, ['message' => 'Resource not accessible by personal access token']);
        $this->expectKey(IssueTrackerException::REJECTED, fn (): array => $this->api->createIssue(IssueTrackerProvider::GitHub, 't', 'acme/roadmap', '', 'T', 'B'));

        $this->http->queue('POST', self::LINEAR, 200, ['data' => ['issueCreate' => ['success' => false, 'issue' => null]]]);
        $this->expectKey(IssueTrackerException::REJECTED, fn (): array => $this->api->createIssue(IssueTrackerProvider::Linear, 't', 'ENG', 'team', 'T', 'B'));
    }
}
