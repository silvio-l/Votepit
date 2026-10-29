<?php

declare(strict_types=1);

namespace Votepit\Extension;

/** Community default: board creation never requires or records operator acknowledgment. */
final class NullBoardOperatorAcknowledgmentGate implements BoardOperatorAcknowledgmentGate
{
    public function validate(array $fields): ?string
    {
        return null;
    }

    public function record(int $boardId, int $userId): void
    {
        // No-op — self-host has no Votepit Cloud contract to acknowledge.
    }

    public function status(int $boardId): ?array
    {
        return null;
    }
}
