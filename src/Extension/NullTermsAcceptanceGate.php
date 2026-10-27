<?php

declare(strict_types=1);

namespace Votepit\Extension;

/** Community default: signup never requires or records terms acceptance. */
final class NullTermsAcceptanceGate implements TermsAcceptanceGate
{
    public function validate(array $fields): ?string
    {
        return null;
    }

    public function record(int $accountId, int $userId): void
    {
        // No-op — self-host has no Votepit Cloud contract to accept.
    }
}
