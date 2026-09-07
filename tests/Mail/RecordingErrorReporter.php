<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Votepit\Monitoring\ErrorReporter;

/** Records every reported exception for assertion — MailVolumeMonitorTest. */
final class RecordingErrorReporter implements ErrorReporter
{
    /** @var list<\Throwable> */
    public array $reported = [];

    public function report(\Throwable $exception): void
    {
        $this->reported[] = $exception;
    }
}
