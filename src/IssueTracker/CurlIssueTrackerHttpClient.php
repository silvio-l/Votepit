<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

use Votepit\Security\Webhook\WebhookUrlGuard;

/**
 * curl-based IssueTrackerHttpClient.
 *
 * SSRF defense in depth (the caller only ever builds api.linear.app /
 * api.github.com URLs):
 *   1. the URL must pass the provider's fixed-host AllowlistedUrlPolicy
 *      (https, exact host, no port/userinfo/query/fragment);
 *   2. WebhookUrlGuard resolves the host and rejects any non-public answer,
 *      and CURLOPT_RESOLVE pins curl to exactly that checked IP (no
 *      DNS-rebinding window between check and connect);
 *   3. redirects are never followed, only https is allowed, TLS peer and
 *      host verification stay on.
 * Short fixed timeouts. Error messages never include the request headers
 * (they carry the token).
 */
final readonly class CurlIssueTrackerHttpClient implements IssueTrackerHttpClient
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS         = 10;

    public function __construct(private WebhookUrlGuard $guard = new WebhookUrlGuard()) {}

    public function send(IssueTrackerProvider $provider, string $method, string $url, array $headers, ?array $json = null): array
    {
        if (!$provider->apiPolicy()->allows($url)) {
            throw new IssueTrackerHttpException('issue tracker: refused URL outside the provider API host');
        }
        $target = $this->guard->resolveForDelivery($url);
        if (!$target instanceof \Votepit\Security\Webhook\WebhookTarget) {
            throw new IssueTrackerHttpException('issue tracker: API host did not resolve to a public address');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new IssueTrackerHttpException('issue tracker: curl_init failed');
        }

        try {
            curl_setopt($ch, CURLOPT_URL, $target->url);
            curl_setopt($ch, CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $target->host, $target->port, $target->ip)]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);
            // GitHub rejects requests without a User-Agent (see CurlOAuthHttpClient).
            curl_setopt($ch, CURLOPT_USERAGENT, 'Votepit-IssueTracker');
            $allHeaders = array_merge(['Accept: application/json'], $headers);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($json ?? []));
                $allHeaders[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

            $raw = curl_exec($ch);
            if (!is_string($raw)) {
                throw new IssueTrackerHttpException('issue tracker: request failed: ' . curl_error($ch));
            }

            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);

            return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
        } finally {
            curl_close($ch);
        }
    }
}
