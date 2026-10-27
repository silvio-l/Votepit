<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\DigestUnsubscribeToken;

final class DigestUnsubscribeTokenTest extends TestCase
{
    private const KEY = 'test-app-key-0123456789abcdef0123456789abcdef';

    public function test_a_signed_token_verifies_to_its_user_id(): void
    {
        $tokens = new DigestUnsubscribeToken(self::KEY);

        self::assertSame(42, $tokens->verify($tokens->sign(42)));
    }

    public function test_token_is_url_safe(): void
    {
        $token = (new DigestUnsubscribeToken(self::KEY))->sign(123456);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
    }

    public function test_tampered_user_id_is_rejected(): void
    {
        $tokens = new DigestUnsubscribeToken(self::KEY);
        [, $mac] = explode('.', $tokens->sign(42), 2);
        $forgedBody = rtrim(strtr(base64_encode('43'), '+/', '-_'), '=');

        self::assertNull($tokens->verify($forgedBody . '.' . $mac));
    }

    public function test_token_from_a_different_key_is_rejected(): void
    {
        $token = (new DigestUnsubscribeToken('another-key-entirely-another-key-entirely'))->sign(42);

        self::assertNull((new DigestUnsubscribeToken(self::KEY))->verify($token));
    }

    public function test_garbage_is_rejected(): void
    {
        $tokens = new DigestUnsubscribeToken(self::KEY);

        foreach (['', '.', 'abc', 'abc.', '.abc', 'a.b.c', '!!.??'] as $garbage) {
            self::assertNull($tokens->verify($garbage), "accepted: {$garbage}");
        }
    }
}
