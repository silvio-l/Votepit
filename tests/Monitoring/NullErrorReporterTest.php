<?php

declare(strict_types=1);

namespace Votepit\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use Votepit\Monitoring\NullErrorReporter;

final class NullErrorReporterTest extends TestCase
{
    public function test_report_is_a_no_op(): void
    {
        $this->expectNotToPerformAssertions();

        (new NullErrorReporter())->report(new \RuntimeException('boom'));
    }
}
