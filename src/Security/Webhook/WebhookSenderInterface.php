<?php

declare(strict_types=1);

namespace Votepit\Security\Webhook;

/**
 * Performs exactly ONE HTTP attempt against an already SSRF-checked,
 * IP-pinned WebhookTarget. Never follows redirects itself — the caller
 * (WebhookDispatcher) decides whether/how to follow a 3xx, because each hop
 * must be re-validated by WebhookUrlGuard before it's dialed.
 *
 * Injectable so WebhookDispatcher's retry/redirect/signing logic can be
 * unit-tested without any real network access.
 */
interface WebhookSenderInterface
{
    /**
     * @param list<string> $headers raw "Name: value" header lines
     */
    public function send(
        WebhookTarget $target,
        array $headers,
        string $body,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
    ): WebhookSendResult;
}
