<?php

declare(strict_types=1);

namespace Votepit\Tests\Participant;

use PHPUnit\Framework\TestCase;
use Votepit\Participant\ParticipantMailPolicy;

/**
 * Decay of the participant mails by last app visit (close-the-loop
 * re-engagement G): recent visitors keep their cadence, 30 idle days turn
 * batched mails monthly, 90 idle days stop them, and an explicit "off" always
 * wins.
 */
final class ParticipantMailPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-26 10:00:00');
    }

    private function idleSince(int $days): \DateTimeImmutable
    {
        return $this->now->modify("-{$days} days");
    }

    public function testRecentVisitorKeepsTheirCadence(): void
    {
        self::assertSame('daily', ParticipantMailPolicy::effectiveCadence('participated', 'daily', $this->idleSince(3), $this->now));
        self::assertSame('weekly', ParticipantMailPolicy::effectiveCadence('new_ideas', 'weekly', $this->idleSince(3), $this->now));
    }

    public function testThirtyIdleDaysTurnBatchedMailsMonthly(): void
    {
        self::assertSame('monthly', ParticipantMailPolicy::effectiveCadence('participated', 'daily', $this->idleSince(30), $this->now));
        self::assertSame('monthly', ParticipantMailPolicy::effectiveCadence('participated', 'weekly', $this->idleSince(45), $this->now));
    }

    public function testNinetyIdleDaysStopMails(): void
    {
        self::assertSame('off', ParticipantMailPolicy::effectiveCadence('participated', 'daily', $this->idleSince(90), $this->now));
        self::assertSame('off', ParticipantMailPolicy::effectiveCadence('my_ideas', 'instant', $this->idleSince(120), $this->now));
    }

    public function testInstantMyIdeasIsNotDecayedByShortInactivity(): void
    {
        self::assertSame('instant', ParticipantMailPolicy::effectiveCadence('my_ideas', 'instant', $this->idleSince(10), $this->now));
    }

    public function testExplicitOffAlwaysWins(): void
    {
        self::assertSame('off', ParticipantMailPolicy::effectiveCadence('participated', 'off', $this->idleSince(0), $this->now));
        self::assertSame('off', ParticipantMailPolicy::effectiveCadence('my_ideas', 'off', null, $this->now));
    }

    public function testMissingBaselineDoesNotDecay(): void
    {
        self::assertSame('daily', ParticipantMailPolicy::effectiveCadence('participated', 'daily', null, $this->now));
        self::assertNull(ParticipantMailPolicy::inactiveDays(null, $this->now));
    }

    public function testPeriodKeysPerGroup(): void
    {
        self::assertSame('daily:2026-10-26', ParticipantMailPolicy::periodKey('daily', $this->now));
        self::assertSame('monthly:2026-10', ParticipantMailPolicy::periodKey('monthly', $this->now));
        self::assertSame('weekly:2026-W44', ParticipantMailPolicy::periodKey('weekly', $this->now));
    }
}
