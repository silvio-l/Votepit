<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\init;

use Sentry\Severity;
use Sentry\State\Scope;

use function Sentry\withScope;

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

    public function reportSecurityEvent(string $message, string $level, array $context): void
    {
        $severity = $level === 'warning' ? Severity::warning() : Severity::info();

        withScope(static function (Scope $scope) use ($message, $severity, $context): void {
            foreach ($context as $key => $value) {
                $scope->setExtra($key, $value);
            }
            captureMessage($message, $severity);
        });
    }
}
