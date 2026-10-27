<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Optional AI tag suggestions for the idea submit form (P-35), contributed
 * by an extension through AppExtension::tagSuggester(). Core ships none: the
 * Community default is "no suggester" and the form simply offers no
 * suggestions.
 *
 * A suggester can only pick among the existing tags core hands it — it never
 * creates tags. Core passes only the title, the body and the names and ids of
 * the requesting board's tags, and discards any id that was not in that list.
 *
 * Contract: suggest() must never throw for an availability problem of the
 * service behind it and must return null whenever it has no verdict (plan
 * does not include it, budget used up, timeout, invalid answer, …).
 */
interface TagSuggester
{
    /** Whether $accountId gets suggestions — for UI hints only, suggest() re-checks. */
    public function availability(int $accountId): TagSuggestionAvailability;

    /**
     * @param list<array{id: int, name: string}> $tags The board's existing tags.
     * @return list<int>|null Tag ids judged to fit the idea (a subset of $tags, best first,
     *                        possibly empty), or null for "no verdict".
     */
    public function suggest(int $accountId, string $title, string $body, array $tags): ?array;
}
