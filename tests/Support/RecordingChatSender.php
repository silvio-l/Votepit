<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;

/**
 * Network-free WebhookSenderInterface double: records every call and
 * replays the queued results (default: 200 OK once the queue is empty).
 */
final class RecordingChatSender implements WebhookSenderInterface
{
    /** @var list<array{target: WebhookTarget, body: string, connect: int, timeout: int}> */
    public array $calls = [];

    /** @param list<WebhookSendResult> $results */
    public function __construct(private array $results = []) {}

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
    {
        $this->calls[] = ['target' => $target, 'body' => $body, 'connect' => $connectTimeoutSeconds, 'timeout' => $timeoutSeconds];
        return array_shift($this->results) ?? WebhookSendResult::response(200, null);
    }
}
