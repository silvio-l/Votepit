<?php

declare(strict_types=1);

namespace Votepit\Security\Webhook;

/**
 * HMAC-SHA256 signing for outgoing board-webhook payloads — the mirror
 * image of verifying an incoming signed webhook; here Votepit is the
 * sender, not the receiver, so there is only a sign() side, not a
 * verify() side.
 *
 * Signs the exact raw JSON bytes that get sent as the request body — a
 * receiver re-computing the signature must do so over those same raw
 * bytes, not a re-serialization, same caveat as any inbound webhook
 * signature check.
 */
final class WebhookSignature
{
    public const HEADER_NAME = 'X-Votepit-Signature';

    /** Hex-encoded HMAC-SHA256 of $body, keyed by the board's webhook secret. */
    public static function sign(string $secret, string $body): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /** Full header value, e.g. "sha256=<hex>" — self-describing algorithm prefix. */
    public static function headerValue(string $secret, string $body): string
    {
        return 'sha256=' . self::sign($secret, $body);
    }
}
