<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\Premoderation;

/**
 * Pure pre-moderation policy, including the 'anonymous' mode of ADR 0006
 * §8: only guest ideas wait for approval, members publish immediately.
 */
final class PremoderationPolicyTest extends TestCase
{
    public function test_anonymous_is_a_known_mode(): void
    {
        self::assertContains(Premoderation::MODE_ANONYMOUS, Premoderation::MODES);
        self::assertSame('anonymous', Premoderation::normalizeMode('anonymous'));
    }

    public function test_unknown_mode_stays_fail_secure(): void
    {
        self::assertSame(Premoderation::MODE_ALL, Premoderation::normalizeMode('guests'));
        self::assertSame(Premoderation::MODE_ALL, Premoderation::normalizeMode(null));
    }

    public function test_off_never_requires_approval(): void
    {
        self::assertFalse(Premoderation::requiresApproval('off', null));
        self::assertFalse(Premoderation::requiresApproval('off', null, isGuest: true));
    }

    public function test_all_requires_approval_for_non_moderators_and_guests(): void
    {
        self::assertTrue(Premoderation::requiresApproval('all', null));
        self::assertFalse(Premoderation::requiresApproval('all', 'moderator'));
        self::assertTrue(Premoderation::requiresApproval('all', null, isGuest: true));
    }

    public function test_anonymous_mode_only_holds_back_guests(): void
    {
        self::assertTrue(Premoderation::requiresApproval('anonymous', null, isGuest: true));
        self::assertFalse(Premoderation::requiresApproval('anonymous', null));
        self::assertFalse(Premoderation::requiresApproval('anonymous', 'owner'));
    }

    public function test_guests_never_bypass_even_with_a_role_argument(): void
    {
        self::assertTrue(Premoderation::requiresApproval('all', 'owner', isGuest: true));
    }

    // Trusted authors (broaden-use-cases issue 11, migration 0089).

    public function test_trusted_author_bypasses_the_all_mode(): void
    {
        self::assertFalse(Premoderation::requiresApproval('all', null, trusted: true));
        self::assertTrue(Premoderation::requiresApproval('all', null, trusted: false));
    }

    public function test_trust_never_lets_a_guest_bypass(): void
    {
        self::assertTrue(Premoderation::requiresApproval('all', null, isGuest: true, trusted: true));
        self::assertTrue(Premoderation::requiresApproval('anonymous', null, isGuest: true, trusted: true));
    }

    public function test_trust_does_not_change_off_or_unknown_modes_for_guests(): void
    {
        self::assertFalse(Premoderation::requiresApproval('off', null, trusted: true));
        self::assertFalse(Premoderation::requiresApproval('garbage', null, trusted: true), 'unknown mode = all, trusted member publishes');
        self::assertTrue(Premoderation::requiresApproval('garbage', null, isGuest: true, trusted: true));
    }

    public function test_is_trusted_reads_the_standing_row_fail_secure(): void
    {
        self::assertFalse(Premoderation::isTrusted(null));
        self::assertFalse(Premoderation::isTrusted(['state' => 'normal', 'trusted_at' => null]));
        self::assertFalse(Premoderation::isTrusted(['state' => 'normal']));
        self::assertTrue(Premoderation::isTrusted(['state' => 'normal', 'trusted_at' => '2026-10-01 10:00:00']));
        self::assertTrue(Premoderation::isTrusted(['state' => 'flagged', 'trusted_at' => '2026-10-01 10:00:00']));
        // Defense in depth: a stale trust next to a lost standing never counts.
        self::assertFalse(Premoderation::isTrusted(['state' => 'warned', 'trusted_at' => '2026-10-01 10:00:00']));
        self::assertFalse(Premoderation::isTrusted(['state' => 'suspended', 'trusted_at' => '2026-10-01 10:00:00']));
        self::assertFalse(Premoderation::isTrusted(['trusted_at' => '2026-10-01 10:00:00']), 'unknown state is not good standing');
    }
}
