<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Optional second opinion on deterministic duplicate candidates
 * (competitor-parity issue 11), contributed by an extension through
 * AppExtension::duplicateConfirmer(). Core ships none: the Community default
 * is "no confirmer", and the deterministic result of DuplicateCandidateFinder
 * is used unchanged.
 *
 * A confirmer can only confirm and rank the candidates core hands it — it
 * never adds ideas of its own, and core discards any id that was not in the
 * candidate set. Core passes only what it already shows the requester: the
 * new title and the candidates' public id/title, all from one board of the
 * requesting account (published, not merged, not deleted).
 *
 * Contract: confirm() must never throw for an availability problem of the
 * service behind it and must return null whenever it has no verdict (plan
 * does not include it, budget used up, timeout, invalid answer, …) — core
 * then keeps the deterministic order.
 */
interface DuplicateConfirmer
{
    /** Whether $accountId's suggestions are confirmed — for UI hints only, confirm() re-checks. */
    public function availability(int $accountId): DuplicateConfirmationAvailability;

    /**
     * @param list<array{id: int, title: string}> $candidates Deterministic candidates, best first.
     * @return list<int>|null Ids judged to be duplicates of $title, best first (a subset of the
     *                        candidate ids, possibly empty), or null for "no verdict".
     */
    public function confirm(int $accountId, string $title, array $candidates): ?array;
}
