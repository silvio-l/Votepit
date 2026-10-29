<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\AnonymousAuthorHasher;
use Votepit\Security\AnonymousVoterHasher;

/**
 * Guest author pseudonym (ADR 0006 §2): account-bound, domain-separated
 * from the anonymous voter hash.
 */
final class AnonymousAuthorHasherTest extends TestCase
{
    private const KEY = 'server-key-0123456789';

    public function test_hash_is_hmac_with_account_bound_context(): void
    {
        $hash = (new AnonymousAuthorHasher(self::KEY))->hashToken(7, 'raw-token');

        self::assertSame(hash_hmac('sha256', 'anon-idea-author:7:raw-token', self::KEY), $hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_same_token_differs_per_account(): void
    {
        $hasher = new AnonymousAuthorHasher(self::KEY);

        self::assertNotSame($hasher->hashToken(1, 'raw-token'), $hasher->hashToken(2, 'raw-token'));
    }

    public function test_author_hash_never_equals_the_voter_hash_of_the_same_raw_value(): void
    {
        $raw = AnonymousVoterHasher::generateToken();

        self::assertNotSame(
            (new AnonymousVoterHasher(self::KEY))->hashToken($raw),
            (new AnonymousAuthorHasher(self::KEY))->hashToken(1, $raw),
        );
    }
}
