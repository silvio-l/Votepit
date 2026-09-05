<?php

declare(strict_types=1);

namespace Votepit\Extension;

/** Community default: nothing has to happen before an account deletion is scheduled. */
final class NullAccountDeletionPrecondition implements AccountDeletionPrecondition
{
    public function beforeScheduling(array $account): ?DeletionBlocked
    {
        return null;
    }
}
