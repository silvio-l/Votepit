<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\IdentityHasher;

/**
 * ADR 0002 (email pseudonymization) — normalize() unifies
 * spellings BEFORE the HMAC, hash() is deterministic per serverKey and
 * different serverKeys must yield unlinkable hashes (otherwise the
 * separation of app_key/identity_server_key would be pointless).
 */
final class IdentityHasherTest extends TestCase
{
    private const KEY_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const KEY_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_normalize_trims_and_lowercases(): void
    {
        $hasher = new IdentityHasher(self::KEY_A);

        self::assertSame('user@example.com', $hasher->normalize('  User@Example.com  '));
    }

    public function test_normalize_applies_nfc_composition(): void
    {
        $hasher = new IdentityHasher(self::KEY_A);

        // 'é' as a decomposed sequence (e + combining acute accent, U+0065 U+0301)
        // must be normalized to the same composed form (U+00E9) as an
        // already-composed 'é' — otherwise two spellings of the same
        // address would produce different hashes.
        $decomposed = "caf\u{0065}\u{0301}@example.com";
        $composed   = "caf\u{00e9}@example.com";

        self::assertSame($hasher->normalize($composed), $hasher->normalize($decomposed));
    }

    public function test_hash_is_deterministic_for_the_same_input_and_key(): void
    {
        $hasher = new IdentityHasher(self::KEY_A);

        self::assertSame($hasher->hash('user@example.com'), $hasher->hash('user@example.com'));
    }

    public function test_hash_is_case_and_whitespace_insensitive(): void
    {
        $hasher = new IdentityHasher(self::KEY_A);

        self::assertSame($hasher->hash('user@example.com'), $hasher->hash('  User@Example.COM  '));
    }

    public function test_hash_differs_for_different_emails(): void
    {
        $hasher = new IdentityHasher(self::KEY_A);

        self::assertNotSame($hasher->hash('a@example.com'), $hasher->hash('b@example.com'));
    }

    public function test_hash_differs_for_different_server_keys(): void
    {
        $hasherA = new IdentityHasher(self::KEY_A);
        $hasherB = new IdentityHasher(self::KEY_B);

        self::assertNotSame($hasherA->hash('user@example.com'), $hasherB->hash('user@example.com'));
    }

    public function test_hash_is_64_char_lowercase_hex(): void
    {
        $hasher = new IdentityHasher(self::KEY_A);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hasher->hash('user@example.com'));
    }
}
