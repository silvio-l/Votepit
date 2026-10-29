<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Whether an account's duplicate suggestions get the optional confirmation
 * step of a DuplicateConfirmer (competitor-parity issue 11). Lets the SPA
 * show either a marker on confirmed suggestions or an honest plan hint.
 */
enum DuplicateConfirmationAvailability: string
{
    /** The account's suggestions are confirmed (best effort — failures fall back silently). */
    case Available = 'available';

    /** The confirmer exists, but the account's plan does not include it. */
    case UpgradeRequired = 'upgrade_required';

    /** The plan includes it, but the account has not switched it on (opt-in) — nothing of it is sent to the confirmer. */
    case Disabled = 'disabled';

    /** The confirmer is switched off for this installation (e.g. not configured). */
    case Off = 'off';
}
