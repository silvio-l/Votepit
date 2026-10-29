<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

/**
 * Test double for IssueTrackerHttpClient — canned responses per
 * "METHOD url", no network. Applies the same fixed-host allowlist as the
 * real client (so a test proves no request ever leaves for a foreign host)
 * and records every request for assertions.
 */
final class InMemoryIssueTrackerHttpClient implements IssueTrackerHttpClient
{
    /** @var array<string, list<array{status: int, body: array<array-key, mixed>}|null>> null = transport failure */
    private array $queues = [];

    /** @var list<array{provider: string, method: string, url: string, headers: list<string>, json: array<array-key, mixed>|null}> */
    public array $requests = [];

    /** @param array<array-key, mixed> $body */
    public function queue(string $method, string $url, int $status, array $body): void
    {
        $this->queues[$method . ' ' . $url][] = ['status' => $status, 'body' => $body];
    }

    public function queueFailure(string $method, string $url): void
    {
        $this->queues[$method . ' ' . $url][] = null;
    }

    public function send(IssueTrackerProvider $provider, string $method, string $url, array $headers, ?array $json = null): array
    {
        if (!$provider->apiPolicy()->allows($url)) {
            throw new IssueTrackerHttpException('issue tracker: refused URL outside the provider API host');
        }
        $this->requests[] = ['provider' => $provider->value, 'method' => $method, 'url' => $url, 'headers' => $headers, 'json' => $json];

        $key = $method . ' ' . $url;
        if (!isset($this->queues[$key]) || $this->queues[$key] === []) {
            throw new IssueTrackerHttpException("issue tracker: no stub for {$key}");
        }
        $next = array_shift($this->queues[$key]);
        if ($next === null) {
            throw new IssueTrackerHttpException("issue tracker: stubbed transport failure for {$key}");
        }

        return $next;
    }
}
