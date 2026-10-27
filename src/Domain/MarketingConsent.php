<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Vocabulary of the proof-carrying marketing consent (migration 0112,
 * the marketing-consent planning notes, W1).
 *
 * The consent text itself lives in the SPA (checkbox + notice copy) and is
 * identified by TEXT_VERSION: bump it whenever the wording changes, so every
 * stored consent proves which text the user actually saw. Sending advertising
 * is decided by the senders in cloud (see MarketingRecipientRepository), never
 * by this class.
 */
final class MarketingConsent
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Where the consent was given: the signup e-mail step or the profile toggle. */
    public const SOURCE_SIGNUP  = 'signup';
    public const SOURCE_PROFILE = 'profile';

    /** Version of the product-news consent text (checkbox label + § 7 Abs. 3 notice). */
    public const TEXT_VERSION = '2026-10-26-1';

    /**
     * Validity of a profile opt-in confirmation link. Longer than the 30 minutes
     * of the notification-e-mail link: the mail is read on the user's own time.
     */
    public const CONFIRM_TTL_SECONDS = 172800;

    private function __construct() {}
}
