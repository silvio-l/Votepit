<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\AuditIpHasher;

final class AuditIpHasherTest extends TestCase
{
    public function test_same_ip_hashes_to_the_same_value_for_correlation(): void
    {
        $hasher = new AuditIpHasher('server-key');
        self::assertSame($hasher->hash('203.0.113.1'), $hasher->hash('203.0.113.1'));
    }

    public function test_different_ips_hash_differently(): void
    {
        $hasher = new AuditIpHasher('server-key');
        self::assertNotSame($hasher->hash('203.0.113.1'), $hasher->hash('203.0.113.2'));
    }

    public function test_output_is_64_hex_characters(): void
    {
        $hasher = new AuditIpHasher('server-key');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hasher->hash('203.0.113.1'));
    }

    public function test_output_never_contains_the_plaintext_ip(): void
    {
        $hasher = new AuditIpHasher('server-key');
        self::assertStringNotContainsString('203.0.113.1', $hasher->hash('203.0.113.1'));
    }

    public function test_different_server_key_produces_a_different_hash(): void
    {
        $a = new AuditIpHasher('server-key-a');
        $b = new AuditIpHasher('server-key-b');
        self::assertNotSame($a->hash('203.0.113.1'), $b->hash('203.0.113.1'));
    }
}
