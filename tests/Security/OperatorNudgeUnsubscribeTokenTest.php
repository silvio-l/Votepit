<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Nudge\OperatorNudgeKind;
use Votepit\Security\DigestUnsubscribeToken;
use Votepit\Security\OperatorNudgeUnsubscribeToken;

final class OperatorNudgeUnsubscribeTokenTest extends TestCase
{
    private const KEY = 'operator-nudge-token-test-key-0123456789abcdef0123456789abcdef';

    public function test_signed_token_round_trips_user_and_kind(): void
    {
        $tokens = new OperatorNudgeUnsubscribeToken(self::KEY);

        $claim = $tokens->verify($tokens->sign(42, OperatorNudgeKind::QuietBoard));

        self::assertSame(['user_id' => 42, 'kind' => OperatorNudgeKind::QuietBoard], $claim);
    }

    public function test_tampered_mac_is_rejected(): void
    {
        $tokens = new OperatorNudgeUnsubscribeToken(self::KEY);
        $token  = $tokens->sign(42, OperatorNudgeKind::Onboarding);
        $first  = $token[0] === 'A' ? 'B' : 'A';

        self::assertNull($tokens->verify($first . substr($token, 1)));
    }

    public function test_body_of_another_kind_with_this_mac_is_rejected(): void
    {
        $tokens   = new OperatorNudgeUnsubscribeToken(self::KEY);
        $onboard  = explode('.', $tokens->sign(42, OperatorNudgeKind::Onboarding));
        $planLimit = explode('.', $tokens->sign(42, OperatorNudgeKind::PlanLimit));

        // A MAC issued for one kind must never validate another kind's body.
        self::assertNull($tokens->verify($planLimit[0] . '.' . $onboard[1]));
    }

    public function test_body_of_another_user_with_this_mac_is_rejected(): void
    {
        $tokens = new OperatorNudgeUnsubscribeToken(self::KEY);
        $mine   = explode('.', $tokens->sign(42, OperatorNudgeKind::Onboarding));
        $other  = explode('.', $tokens->sign(43, OperatorNudgeKind::Onboarding));

        self::assertNull($tokens->verify($other[0] . '.' . $mine[1]));
    }

    public function test_token_signed_with_another_key_is_rejected(): void
    {
        $token = (new OperatorNudgeUnsubscribeToken(str_repeat('b', 64)))->sign(7, OperatorNudgeKind::PlanLimit);

        self::assertNull((new OperatorNudgeUnsubscribeToken(self::KEY))->verify($token));
    }

    public function test_digest_unsubscribe_token_is_not_accepted_here(): void
    {
        $digest = (new DigestUnsubscribeToken(self::KEY))->sign(42);

        self::assertNull((new OperatorNudgeUnsubscribeToken(self::KEY))->verify($digest));
    }

    public function test_malformed_input_is_rejected(): void
    {
        $tokens = new OperatorNudgeUnsubscribeToken(self::KEY);

        self::assertNull($tokens->verify(''));
        self::assertNull($tokens->verify('not-a-token'));
        self::assertNull($tokens->verify('.'));
        self::assertNull($tokens->verify('a.b.c'));
    }
}
