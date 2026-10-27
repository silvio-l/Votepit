<?php

declare(strict_types=1);

namespace Votepit\Cron;

use Cron\CronExpression;

/**
 * Wall-clock schedule arithmetic for the cron manager. Everything is UTC
 * (no DST gaps or doubled hours) and minute-granular, matching the 5-field
 * expressions of CronJobDefinition::$cronExpr.
 */
final class CronSchedule
{
    /** Latest occurrence <= $now (seconds dropped), in UTC. */
    public static function latestSlot(string $cronExpr, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $at = self::utcMinute($now);

        return self::fromMutable(CronExpression::factory($cronExpr)->getPreviousRunDate(self::mutable($at), 0, true));
    }

    /** First occurrence strictly after $now, in UTC. */
    public static function nextSlot(string $cronExpr, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $at = self::utcMinute($now);

        return self::fromMutable(CronExpression::factory($cronExpr)->getNextRunDate(self::mutable($at), 0, false));
    }

    /** Seconds between the two most recent occurrences — how often the job is meant to run. */
    public static function periodSeconds(string $cronExpr, \DateTimeImmutable $now): int
    {
        $latest   = self::latestSlot($cronExpr, $now);
        $previous = self::latestSlot($cronExpr, $latest->modify('-1 minute'));

        return $latest->getTimestamp() - $previous->getTimestamp();
    }

    /** Truncates to the minute and converts to UTC. */
    public static function utcMinute(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), 0);
    }

    private static function mutable(\DateTimeImmutable $at): \DateTime
    {
        return new \DateTime($at->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    private static function fromMutable(\DateTime $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }
}
