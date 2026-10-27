<?php

declare(strict_types=1);

namespace Votepit\Tests\Resilience;

use Votepit\Resilience\CircuitBreaker;
use Votepit\Tests\Support\IntegrationTestCase;

/** OWASP/BSI audit remediation issue 13: circuit-breaker state machine. */
final class CircuitBreakerTest extends IntegrationTestCase
{
    public function test_stays_closed_below_the_failure_threshold(): void
    {
        $breaker = new CircuitBreaker($this->conn);

        $breaker->recordFailure('smtp', 3);
        $breaker->recordFailure('smtp', 3);

        self::assertFalse($breaker->isOpen('smtp', 120));
    }

    public function test_opens_after_the_configured_number_of_consecutive_failures(): void
    {
        $breaker = new CircuitBreaker($this->conn);

        $breaker->recordFailure('smtp', 3);
        $breaker->recordFailure('smtp', 3);
        $breaker->recordFailure('smtp', 3);

        self::assertTrue($breaker->isOpen('smtp', 120));
    }

    public function test_closes_immediately_after_a_recorded_success(): void
    {
        $breaker = new CircuitBreaker($this->conn);

        $breaker->recordFailure('smtp', 3);
        $breaker->recordFailure('smtp', 3);
        $breaker->recordFailure('smtp', 3);
        self::assertTrue($breaker->isOpen('smtp', 120));

        $breaker->recordSuccess('smtp');

        self::assertFalse($breaker->isOpen('smtp', 120));
    }

    public function test_half_open_after_the_cooldown_elapses(): void
    {
        $breaker = new CircuitBreaker($this->conn);

        $breaker->recordFailure('smtp', 1);
        self::assertTrue($breaker->isOpen('smtp', 120));

        // A cooldown of 0 seconds has always already elapsed.
        self::assertFalse($breaker->isOpen('smtp', 0));
    }

    public function test_a_failure_while_half_open_reopens_the_circuit(): void
    {
        $breaker = new CircuitBreaker($this->conn);

        $breaker->recordFailure('smtp', 1);
        self::assertFalse($breaker->isOpen('smtp', 0)); // half-open (cooldown 0)

        $breaker->recordFailure('smtp', 1);

        self::assertTrue($breaker->isOpen('smtp', 120));
    }

    public function test_services_track_independently(): void
    {
        $breaker = new CircuitBreaker($this->conn);

        $breaker->recordFailure('smtp', 1);

        self::assertTrue($breaker->isOpen('smtp', 120));
        self::assertFalse($breaker->isOpen('translation', 120));
        self::assertFalse($breaker->isOpen('payments', 120));
    }
}
