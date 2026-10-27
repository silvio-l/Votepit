<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

/**
 * Outbound HTTP seam for the Linear/GitHub API calls (same interface +
 * curl-impl + in-memory-fake pattern as Translation\TranslationHttpClient;
 * this codebase has no Guzzle/PSR HTTP client dependency).
 *
 * Implementations MUST refuse any URL outside the provider's fixed API host
 * (IssueTrackerProvider::apiPolicy()) — the caller already builds only such
 * URLs, the check here is defense in depth.
 */
interface IssueTrackerHttpClient
{
    /**
     * @param 'GET'|'POST'                 $method
     * @param list<string>                 $headers raw header lines ("Name: value")
     * @param array<array-key, mixed>|null $json    JSON body (POST only)
     * @return array{status: int, body: array<array-key, mixed>}
     * @throws IssueTrackerHttpException on a transport-level failure or a refused URL
     */
    public function send(IssueTrackerProvider $provider, string $method, string $url, array $headers, ?array $json = null): array;
}
