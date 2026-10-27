<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Whether an account gets AI tag suggestions while an idea is written (P-35).
 * Lets the SPA show suggestions or stay silent without a hint.
 */
enum TagSuggestionAvailability: string
{
    /** Suggestions are offered (best effort — failures fall back to no suggestions). */
    case Available = 'available';

    /** The suggester exists, but the account's plan does not include it. */
    case UpgradeRequired = 'upgrade_required';

    /** The plan includes it, but the account has not switched it on (opt-in). */
    case Disabled = 'disabled';

    /** The suggester is switched off for this installation (e.g. not configured). */
    case Off = 'off';
}
