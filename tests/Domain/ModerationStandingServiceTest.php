<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\ModerationStandingService;

/**
 * Unit tests for Domain\ModerationStandingService (board-operator-
 * compliance issue 10) — pure logic, no DB, exercises the state
 * transitions at the configured thresholds and monotonic behaviour under
 * different configs.
 */
final class ModerationStandingServiceTest extends TestCase
{
    /** @return array{flagged_at:int, warned_at:int, suspended_at:int} */
    private function defaultConfig(): array
    {
        return ['flagged_at' => 1, 'warned_at' => 3, 'suspended_at' => 5];
    }

    public function test_first_violation_from_no_row_yields_flagged(): void
    {
        $service = new ModerationStandingService();

        $next = $service->computeNextState(null, $this->defaultConfig());

        self::assertSame(1, $next['violation_count']);
        self::assertSame('flagged', $next['state']);
    }

    public function test_state_stays_normal_below_flagged_threshold(): void
    {
        $service = new ModerationStandingService();
        $config  = ['flagged_at' => 2, 'warned_at' => 4, 'suspended_at' => 6];

        // First violation with a raised flagged_at threshold of 2: still normal.
        $next = $service->computeNextState(null, $config);

        self::assertSame(1, $next['violation_count']);
        self::assertSame('normal', $next['state']);
    }

    public function test_transitions_at_each_configured_threshold(): void
    {
        $service = new ModerationStandingService();
        $config  = $this->defaultConfig();

        $standing = null;
        $expectedStates = [
            1 => 'flagged',
            2 => 'flagged',
            3 => 'warned',
            4 => 'warned',
            5 => 'suspended',
            6 => 'suspended',
        ];

        foreach ($expectedStates as $violationNumber => $expectedState) {
            $standing = $service->computeNextState($standing, $config);
            self::assertSame($violationNumber, $standing['violation_count']);
            self::assertSame($expectedState, $standing['state'], "violation #{$violationNumber}");
        }
    }

    public function test_monotonic_never_regresses_across_repeated_calls(): void
    {
        $service = new ModerationStandingService();
        $config  = $this->defaultConfig();

        $standing = ['violation_count' => 5, 'state' => 'suspended'];
        $next     = $service->computeNextState($standing, $config);

        self::assertSame(6, $next['violation_count']);
        self::assertSame('suspended', $next['state']);
    }

    public function test_different_config_shifts_thresholds(): void
    {
        $service = new ModerationStandingService();
        $config  = ['flagged_at' => 1, 'warned_at' => 2, 'suspended_at' => 3];

        $standing = null;
        $standing = $service->computeNextState($standing, $config);
        self::assertSame('flagged', $standing['state']);

        $standing = $service->computeNextState($standing, $config);
        self::assertSame('warned', $standing['state']);

        $standing = $service->computeNextState($standing, $config);
        self::assertSame('suspended', $standing['state']);
    }

    public function test_violation_count_persists_across_calls_even_when_state_is_provided_without_it(): void
    {
        $service = new ModerationStandingService();

        // A row missing violation_count defaults to 0 before incrementing —
        // defensive against a malformed/partial standing array.
        $next = $service->computeNextState(['state' => 'warned'], $this->defaultConfig());

        self::assertSame(1, $next['violation_count']);
        self::assertSame('flagged', $next['state']);
    }
}
