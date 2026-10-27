<?php

declare(strict_types=1);

namespace Votepit\Security\OAuth;

/**
 * Outbound HTTP seam for the OAuth2 token exchange + profile fetch —
 * mirrors Votepit\Telemetry\MatomoEventTracker's
 * interface+curl-impl+in-memory-fake pattern (this codebase has no Guzzle/
 * PSR HTTP client dependency, curl only).
 *
 * Unlike the Matomo tracker, failures here must NOT be swallowed — an OAuth
 * login that silently "succeeds" against a broken provider call would be a
 * security bug, not a missed analytics event. See OAuthHttpException.
 */
interface OAuthHttpClient
{
    /**
     * @param array<string, string> $params  form-encoded body
     * @param list<string>          $headers raw header lines ("Name: value")
     * @return array{status: int, body: array<array-key, mixed>}
     * @throws OAuthHttpException on a transport-level failure
     */
    public function postForm(string $url, array $params, array $headers = []): array;

    /**
     * @param list<string> $headers raw header lines ("Name: value")
     * @return array{status: int, body: array<array-key, mixed>}
     * @throws OAuthHttpException on a transport-level failure
     */
    public function getJson(string $url, array $headers = []): array;
}
