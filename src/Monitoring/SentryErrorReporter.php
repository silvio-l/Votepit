<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

use function Sentry\captureException;
use function Sentry\init;

/**
 * Active only when Config::$sentryDsn is set (hosted operation).
 * Self-hosting runs with NullErrorReporter by default — no monitoring requirement.
 */
final readonly class SentryErrorReporter implements ErrorReporter
{
    public function __construct(string $dsn, string $environment)
    {
        init(['dsn' => $dsn, 'environment' => $environment]);
    }

    public function report(\Throwable $exception): void
    {
        captureException($exception);
    }
}
