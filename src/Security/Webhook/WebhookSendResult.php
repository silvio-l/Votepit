<?php

declare(strict_types=1);

namespace Votepit\Security\Webhook;

/**
 * Outcome of ONE HTTP attempt against a single, already-pinned
 * WebhookTarget (one redirect hop or one retry attempt — see
 * WebhookDispatcher, which composes several of these into one delivery).
 */
final readonly class WebhookSendResult
{
    private function __construct(
        public bool $transportOk,
        public ?int $statusCode,
        public ?string $locationHeader,
        public ?string $error,
    ) {}

    public static function response(int $statusCode, ?string $locationHeader): self
    {
        return new self(true, $statusCode, $locationHeader, null);
    }

    public static function transportError(string $error): self
    {
        return new self(false, null, null, $error);
    }

    public function isRedirect(): bool
    {
        return $this->transportOk && $this->statusCode !== null && $this->statusCode >= 300 && $this->statusCode < 400 && $this->locationHeader !== null;
    }

    public function isSuccess(): bool
    {
        return $this->transportOk && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
