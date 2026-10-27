<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Extension hook for Cloud-only signup terms/AUP/Privacy consent capture.
 *
 * Core knows nothing about these three documents, their current versions,
 * or the `account_terms_acceptances` table (Cloud-only schema, see
 * `cloud/migrations/0009_account_terms_acceptances.php`) — the contractual
 * relationship this consent is about only exists for the hosted edition's
 * customers, exactly like AccountDeletionPrecondition's billing-cancellation
 * hook. SignupAccountAction consults this gate via
 * ExtensionLoader::termsAcceptanceGate():
 *
 *   - validate() runs BEFORE any write. A non-null return rejects the whole
 *     signup with a 422 carrying that string as `error.key` — no account,
 *     member or board is created.
 *   - record() runs INSIDE SignupAccountAction's existing transaction,
 *     right after the new owner membership is inserted, so the acceptance
 *     rows are exactly as atomic as the account itself: either the whole
 *     signup lands together, or none of it does.
 *
 * The Community default is NullTermsAcceptanceGate: validate() always
 * accepts (no field is ever required) and record() is a no-op — self-host
 * never asks for or stores this consent, because there is no Votepit Cloud
 * contract for a self-hosted install to accept.
 */
interface TermsAcceptanceGate
{
    /**
     * @param array<string, mixed> $fields The raw POST body fields submitted to /signup/account.
     * @return string|null null = accepted; otherwise the `error.key` to reject the signup with (422).
     */
    public function validate(array $fields): ?string;

    /** Persists acceptance rows inside the caller's (SignupAccountAction's) transaction. */
    public function record(int $accountId, int $userId): void;
}
