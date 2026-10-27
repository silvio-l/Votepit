<?php

declare(strict_types=1);

namespace Votepit\Participant;

/**
 * Decay of participant mails by last app visit (close-the-loop /
 * re-engagement decision 6, no tracking in mails).
 *
 *  - no visit for 30 days: every batched cadence (daily, weekly) drops to
 *    monthly, and so does an instant participant mail (only the author's own
 *    instant mails stay instant);
 *  - no visit for 90 days: participant mails stop, except the author-instant
 *    mails about their own idea (category my_ideas + instant), which are
 *    never decayed.
 *
 * The baseline is the last recorded app visit, falling back to the account's
 * creation date when none was recorded yet. A null baseline (guests, who have
 * no visit signal) never decays.
 */
final class ParticipantMailPolicy
{
    public const MONTHLY_AFTER_DAYS = 30;
    public const STOP_AFTER_DAYS    = 90;

    public const MONTHLY = 'monthly';
    public const STOP    = 'off';

    /**
     * The effective delivery mode for a category at $now.
     *
     * Returns one of: 'instant' (send at once), 'daily', 'weekly', 'monthly'
     * (batched, the digest picks the period), or 'off' (drop, no mail).
     */
    public static function effectiveCadence(
        string $category,
        string $cadence,
        ?\DateTimeImmutable $baseline,
        \DateTimeImmutable $now,
    ): string {
        if ($cadence === ParticipantMailCategory::CADENCE_OFF) {
            return self::STOP;
        }

        // Decay applies to every category, including instant my_ideas: a long
        // absent author stops receiving instant mails (see the checks below).
        $inactiveDays = self::inactiveDays($baseline, $now);
        if ($inactiveDays !== null && $inactiveDays >= self::STOP_AFTER_DAYS) {
            return self::STOP;
        }
        if ($inactiveDays !== null && $inactiveDays >= self::MONTHLY_AFTER_DAYS) {
            return self::MONTHLY;
        }

        // Recent visitor: instant stays instant (participated), batched stays batched.
        return $cadence;
    }

    /** Whole days since the baseline, null when there is no baseline. */
    public static function inactiveDays(?\DateTimeImmutable $baseline, \DateTimeImmutable $now): ?int
    {
        if (!$baseline instanceof \DateTimeImmutable) {
            return null;
        }

        $seconds = $now->getTimestamp() - $baseline->getTimestamp();

        return $seconds < 0 ? 0 : intdiv($seconds, 86400);
    }

    /**
     * The cadence period identifier a batched mail is claimed under: one mail
     * per recipient, cadence group and period. Monthly is keyed by calendar
     * month, daily by calendar day, weekly by ISO week.
     */
    public static function periodKey(string $group, \DateTimeImmutable $now): string
    {
        return match ($group) {
            ParticipantMailCategory::CADENCE_DAILY   => 'daily:' . $now->format('Y-m-d'),
            ParticipantMailCategory::CADENCE_WEEKLY  => 'weekly:' . $now->format('o-\WW'),
            self::MONTHLY                             => 'monthly:' . $now->format('Y-m'),
            default                                   => throw new \InvalidArgumentException('Unknown cadence group: ' . $group),
        };
    }
}
