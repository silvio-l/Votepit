<?php

declare(strict_types=1);

namespace Votepit\Cron;

/** What the self-alert for one job should do on this tick (see CronAlertPolicy). */
enum AlertDecision: string
{
    case None = 'none';
    /** Consecutive failures reached the threshold while no alert was open. */
    case Alert = 'alert';
    /** Still failing, and the last alert is at least six hours old. */
    case Realert = 'realert';
    /** The job succeeded again after an open alert. */
    case Recover = 'recover';
}
