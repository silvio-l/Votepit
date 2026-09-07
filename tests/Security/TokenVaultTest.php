<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\TokenVault;

final class TokenVaultTest extends TestCase
{
    public function test_generate_yields_64_hex_token_and_matching_sha256_hash(): void
    {
        $vault = new TokenVault();
        $pair  = $vault->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pair['token']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pair['hash']);
        self::assertSame(hash('sha256', $pair['token']), $pair['hash']);
    }

    public function test_generate_is_unique_per_call(): void
    {
        $vault = new TokenVault();

        self::assertNotSame($vault->generate()['token'], $vault->generate()['token']);
    }

    public function test_verify_accepts_correct_token_and_rejects_wrong_one(): void
    {
        $vault = new TokenVault();
        $pair  = $vault->generate();

        self::assertTrue($vault->verify($pair['token'], $pair['hash']));
        self::assertFalse($vault->verify('deadbeef', $pair['hash']));
    }
}
