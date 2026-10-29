<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\IpRateKey;

/**
 * Rate-limit identity for the guest buckets (ADR 0006 §4, review 3b): an
 * IPv6 end-user line usually owns a whole /64, so it counts as one client.
 */
final class IpRateKeyTest extends TestCase
{
    public function test_ipv4_is_used_as_is(): void
    {
        self::assertSame('203.0.113.7', IpRateKey::of('203.0.113.7'));
    }

    public function test_ipv6_addresses_in_the_same_64_share_one_key(): void
    {
        $a = IpRateKey::of('2001:db8:1:2::1');
        $b = IpRateKey::of('2001:0db8:0001:0002:ffff:ffff:ffff:ffff');

        self::assertSame('2001:db8:1:2::/64', $a);
        self::assertSame($a, $b);
        self::assertNotSame($a, IpRateKey::of('2001:db8:1:3::1'));
    }

    public function test_ipv4_mapped_ipv6_is_treated_as_ipv4(): void
    {
        self::assertSame('203.0.113.7', IpRateKey::of('::ffff:203.0.113.7'));
    }

    public function test_invalid_input_has_no_key(): void
    {
        self::assertNull(IpRateKey::of(null));
        self::assertNull(IpRateKey::of(''));
        self::assertNull(IpRateKey::of('not-an-ip'));
    }
}
