<?php

declare(strict_types=1);

namespace Votepit\Security\Webhook;

/**
 * curl-based WebhookSenderInterface implementation. `ext-curl` is already a
 * required composer dependency (used by the Symfony HTTP client stack
 * elsewhere) — no new dependency needed for outbound webhooks.
 *
 * The DNS-rebinding defense lives HERE: CURLOPT_RESOLVE pins
 * "$host:$port:$ip" so curl connects to the exact IP WebhookUrlGuard just
 * checked, instead of re-resolving the hostname itself at connect time
 * (which would reopen the TOCTOU window between check and connect).
 * CURLOPT_SSL_VERIFYHOST/VERIFYPEER stay on — TLS still validates against
 * the hostname, not the pinned IP, so certificate validation is unaffected.
 *
 * CURLOPT_FOLLOWLOCATION is deliberately OFF: a redirect target must be
 * re-validated by WebhookUrlGuard before it's ever dialed (see
 * WebhookDispatcher) — curl must never silently chase a redirect on its
 * own.
 */
final readonly class CurlWebhookSender implements WebhookSenderInterface
{
    public function send(
        WebhookTarget $target,
        array $headers,
        string $body,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
    ): WebhookSendResult {
        $ch = curl_init();
        if ($ch === false) {
            return WebhookSendResult::transportError('curl_init failed');
        }

        try {
            $resolveEntry = sprintf('%s:%d:%s', $target->host, $target->port, $target->ip);

            // Individual curl_setopt() calls, not curl_setopt_array(): the
            // latter's PHPStan stub demands overly-narrow per-offset value
            // types (e.g. non-empty-string) that our generically-typed
            // values can't satisfy without a type cast, which we'd rather
            // not sprinkle in just to please the stub.
            curl_setopt($ch, CURLOPT_URL, $target->url);
            curl_setopt($ch, CURLOPT_RESOLVE, [$resolveEntry]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSeconds);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);

            $raw = curl_exec($ch);
            if ($raw === false || !is_string($raw)) {
                return WebhookSendResult::transportError(curl_error($ch));
            }

            $statusCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders  = substr($raw, 0, $headerSize);
            $location    = $this->extractHeader($rawHeaders, 'Location');

            return WebhookSendResult::response($statusCode, $location);
        } finally {
            curl_close($ch);
        }
    }

    private function extractHeader(string $rawHeaders, string $name): ?string
    {
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }
}
