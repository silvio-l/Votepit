<?php

declare(strict_types=1);

namespace Votepit\Security\Webhook;

/**
 * A webhook URL pinned to one already-validated public IP, immediately
 * before use (WebhookUrlGuard::resolveForDelivery()).
 *
 * $url is the ORIGINAL url (used for TLS SNI / Host header / path+query);
 * $ip is what the HTTP client is told to actually connect to (curl
 * CURLOPT_RESOLVE pinning) — this is the DNS-rebinding defense: the
 * hostname is resolved and range-checked exactly once, and the connection
 * uses that exact answer instead of letting the HTTP client re-resolve the
 * hostname itself at connect time.
 */
final readonly class WebhookTarget
{
    /**
     * @param non-empty-string $url
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {}
}
