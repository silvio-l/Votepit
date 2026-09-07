<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

/** Default when no monitoring DSN is configured (self-host default, fail-safe no-op). */
final class NullErrorReporter implements ErrorReporter
{
    public function report(\Throwable $exception): void {}
}
