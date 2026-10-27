<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\ViewDedupHasher;

/**
 * Covers ViewDedupHasher — HMAC-SHA256(ip|user-agent, serverKey), scoped per
 * idea via a domain-separated prefix, used by IdeaViewTracker to deduplicate
 * view counts without cookies or persisted plaintext IP/User-Agent.
 */
final class ViewDedupHasherTest extends TestCase
{
    private const KEY = 'test-server-key';

    public function test_same_input_produces_the_same_hash(): void
    {
        $hasher = new ViewDedupHasher(self::KEY);

        self::assertSame(
            $hasher->hash(1, '203.0.113.1', 'Mozilla/5.0'),
            $hasher->hash(1, '203.0.113.1', 'Mozilla/5.0'),
        );
    }

    public function test_different_ip_produces_a_different_hash(): void
    {
        $hasher = new ViewDedupHasher(self::KEY);

        self::assertNotSame(
            $hasher->hash(1, '203.0.113.1', 'Mozilla/5.0'),
            $hasher->hash(1, '203.0.113.2', 'Mozilla/5.0'),
        );
    }

    public function test_different_idea_produces_a_different_hash_for_the_same_visitor(): void
    {
        $hasher = new ViewDedupHasher(self::KEY);

        self::assertNotSame(
            $hasher->hash(1, '203.0.113.1', 'Mozilla/5.0'),
            $hasher->hash(2, '203.0.113.1', 'Mozilla/5.0'),
        );
    }

    public function test_hash_is_a_64_character_hex_string(): void
    {
        $hash = (new ViewDedupHasher(self::KEY))->hash(1, '203.0.113.1', 'Mozilla/5.0');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }
}
