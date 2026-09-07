<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Result of an AccountDeletionPrecondition that refuses to let a deletion
 * proceed. Rendered by AccountDeleteAction as
 * `{"error": {"key": ..., "message": ...}}` with the given HTTP status.
 */
final readonly class DeletionBlocked
{
    public function __construct(
        public int $httpStatus,
        public string $key,
        public string $message,
    ) {}
}
