<?php

declare(strict_types=1);

namespace Votepit\Monitoring;

interface ErrorReporter
{
    public function report(\Throwable $exception): void;
}
