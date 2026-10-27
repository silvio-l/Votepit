<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\ParticipantUnsubscribeToken;

/**
 * The participant-mail unsubscribe capability: bound to one recipient and one
 * category, tamper-evident, and never accepted under another key.
 */
final class ParticipantUnsubscribeTokenTest extends TestCase
{
    private ParticipantUnsubscribeToken $tokens;

    protected function setUp(): void
    {
        $this->tokens = new ParticipantUnsubscribeToken(str_repeat('a', 64));
    }

    public function testUserTokenRoundTripsRecipientAndCategory(): void
    {
        $claims = $this->tokens->verify($this->tokens->signForUser(42, 'my_ideas'));

        self::assertSame(['user_id' => 42, 'guest_key' => null, 'category' => 'my_ideas'], $claims);
    }

    public function testGuestTokenRoundTripsGuestKey(): void
    {
        $guestKey = hash('sha256', 'guest@example.com');
        $claims   = $this->tokens->verify($this->tokens->signForGuest($guestKey, 'participated'));

        self::assertSame(['user_id' => null, 'guest_key' => $guestKey, 'category' => 'participated'], $claims);
    }

    public function testEveryCategoryIncludingAllIsAccepted(): void
    {
        foreach (['my_ideas', 'participated', 'new_ideas', 'all'] as $category) {
            $claims = $this->tokens->verify($this->tokens->signForUser(7, $category));
            self::assertNotNull($claims, $category);
            self::assertSame($category, $claims['category']);
        }
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token    = $this->tokens->signForUser(42, 'my_ideas');
        $flipped  = $token[0] === 'A' ? 'B' . substr($token, 1) : 'A' . substr($token, 1);

        self::assertNull($this->tokens->verify($flipped));
    }

    public function testTokenFromAnotherKeyIsRejected(): void
    {
        $foreign = new ParticipantUnsubscribeToken(str_repeat('b', 64));

        self::assertNull($this->tokens->verify($foreign->signForUser(42, 'all')));
    }

    public function testUserTokenDoesNotVerifyAsGuestToken(): void
    {
        // Domain separation: a user id must never be readable as a guest key.
        $claims = $this->tokens->verify($this->tokens->signForUser(42, 'participated'));

        self::assertNotNull($claims);
        self::assertNull($claims['guest_key']);
    }

    public function testMalformedInputFailsSecure(): void
    {
        self::assertNull($this->tokens->verify(''));
        self::assertNull($this->tokens->verify('not-a-token'));
        self::assertNull($this->tokens->verify('!!!'));
    }
}
