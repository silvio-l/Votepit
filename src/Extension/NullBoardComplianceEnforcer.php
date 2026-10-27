<?php

declare(strict_types=1);

namespace Votepit\Extension;

/** Community default: no Cloud contract to enforce, nothing is ever frozen. */
final class NullBoardComplianceEnforcer implements BoardComplianceEnforcer
{
    public function enforceForAccount(int $accountId): array
    {
        return [];
    }
}
