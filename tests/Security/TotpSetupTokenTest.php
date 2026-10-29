<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\TotpSetupToken;

final class TotpSetupTokenTest extends TestCase
{
    private function token(): TotpSetupToken
    {
        return new TotpSetupToken(str_repeat('a', 64));
    }

    public function test_round_trip_returns_the_secret_for_the_correct_user(): void
    {
        $token  = $this->token();
        $blob   = $token->sign(42, 'JBSWY3DPEHPK3PXP');

        self::assertSame('JBSWY3DPEHPK3PXP', $token->verify($blob, 42));
    }

    public function test_rejects_for_a_different_user_id(): void
    {
        $token = $this->token();
        $blob  = $token->sign(42, 'JBSWY3DPEHPK3PXP');

        self::assertNull($token->verify($blob, 99));
    }

    public function test_rejects_tampered_blob(): void
    {
        $token = $this->token();
        $blob  = $token->sign(42, 'JBSWY3DPEHPK3PXP');

        self::assertNull($token->verify($blob . 'x', 42));
    }

    public function test_rejects_a_blob_signed_with_a_different_key(): void
    {
        $tokenA = new TotpSetupToken(str_repeat('a', 64));
        $tokenB = new TotpSetupToken(str_repeat('b', 64));

        $blob = $tokenA->sign(42, 'JBSWY3DPEHPK3PXP');

        self::assertNull($tokenB->verify($blob, 42));
    }

    public function test_rejects_malformed_blob(): void
    {
        $token = $this->token();

        self::assertNull($token->verify('not-a-valid-blob', 42));
        self::assertNull($token->verify('', 42));
    }
}
