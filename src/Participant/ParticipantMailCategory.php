<?php

declare(strict_types=1);

namespace Votepit\Participant;

/**
 * Categories and cadences of participant mails (close-the-loop slice 03,
 * re-engagement decision 2). Pure value rules, no I/O.
 *
 *  - my_ideas:      mails about ideas the user wrote (status changes, comments)
 *  - participated:  mails about ideas the user voted on, commented on or follows
 *  - new_ideas:     opt-in digest of new public ideas on boards the user took
 *                   part in (weekly or off; default off, never instant)
 */
final class ParticipantMailCategory
{
    public const MY_IDEAS    = 'my_ideas';
    public const PARTICIPATED = 'participated';
    public const NEW_IDEAS   = 'new_ideas';

    public const CADENCE_INSTANT = 'instant';
    public const CADENCE_DAILY   = 'daily';
    public const CADENCE_WEEKLY  = 'weekly';
    public const CADENCE_OFF     = 'off';

    /** @var list<string> */
    public const ALL = [self::MY_IDEAS, self::PARTICIPATED, self::NEW_IDEAS];

    /** @return list<string> cadences a user may choose for the category */
    public static function allowedCadences(string $category): array
    {
        return match ($category) {
            self::MY_IDEAS, self::PARTICIPATED => [self::CADENCE_INSTANT, self::CADENCE_DAILY, self::CADENCE_WEEKLY, self::CADENCE_OFF],
            self::NEW_IDEAS => [self::CADENCE_WEEKLY, self::CADENCE_OFF],
            default => [],
        };
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }

    public static function isValidCadence(string $category, string $cadence): bool
    {
        return in_array($cadence, self::allowedCadences($category), true);
    }

    /** The cadence applied when the user has no stored row (see migration 0105). */
    public static function defaultCadence(string $category): string
    {
        return match ($category) {
            self::MY_IDEAS    => self::CADENCE_INSTANT,
            self::PARTICIPATED => self::CADENCE_DAILY,
            default           => self::CADENCE_OFF,
        };
    }
}
