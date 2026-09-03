<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Runs before an owner-requested account deletion is scheduled
 * (AccountDeleteAction::requestDeletion()).
 *
 * A hosted-service extension uses this to settle external state first —
 * e.g. cancel a paid subscription at the payment provider — so nothing keeps
 * billing an account that is about to disappear. If that cannot be done the
 * whole request must fail closed: return a DeletionBlocked and the deletion
 * is NOT scheduled.
 *
 * The Community default is NullAccountDeletionPrecondition (always allows).
 */
interface AccountDeletionPrecondition
{
    /**
     * @param array<string, mixed> $account The account row as returned by AccountRepository::findById().
     * @return DeletionBlocked|null null = proceed with scheduling the deletion.
     */
    public function beforeScheduling(array $account): ?DeletionBlocked;
}
