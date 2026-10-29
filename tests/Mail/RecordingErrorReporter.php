<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Votepit\Monitoring\ErrorReporter;

/** Records every reported exception for assertion — MailVolumeMonitorTest. */
final class RecordingErrorReporter implements ErrorReporter
{
    /** @var list<\Throwable> */
    public array $reported = [];

    /** @var list<array{message: string, level: string, context: array<string, mixed>}> */
    public array $securityEvents = [];

    public function report(\Throwable $exception): void
    {
        $this->reported[] = $exception;
    }

    public function reportSecurityEvent(string $message, string $level, array $context): void
    {
        $this->securityEvents[] = ['message' => $message, 'level' => $level, 'context' => $context];
    }
}
