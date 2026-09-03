<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\SmtpHostPolicy;

/**
 * Security review — outbound SMTP target policy (SSRF / internal port
 * probing via tenant-configured relays on the shared cloud host).
 *
 * DNS is stubbed through the injectable resolver; no test touches the network.
 */
final class SmtpHostPolicyTest extends TestCase
{
    /** @param array<string, list<string>> $table */
    private function cloudPolicy(array $table = []): SmtpHostPolicy
    {
        return new SmtpHostPolicy(true, static fn (string $host): array => $table[strtolower($host)] ?? []);
    }

    public function test_self_host_mode_is_permissive_except_for_empty_host(): void
    {
        $policy = new SmtpHostPolicy(false, static fn (string $host): array => ['127.0.0.1']);

        self::assertNull($policy->rejectionReason('localhost'));
        self::assertNull($policy->rejectionReason('127.0.0.1'));
        self::assertNull($policy->rejectionReason('mail'));
        self::assertNotNull($policy->rejectionReason(''));
        self::assertFalse($policy->isRestricted());
    }

    /** @return iterable<string, array{string}> */
    public static function blockedIpLiterals(): iterable
    {
        yield 'loopback'            => ['127.0.0.1'];
        yield 'loopback other'      => ['127.13.37.1'];
        yield 'this-network'        => ['0.0.0.0'];
        yield 'rfc1918 10'          => ['10.0.0.5'];
        yield 'rfc1918 172'         => ['172.31.255.1'];
        yield 'rfc1918 192'         => ['192.168.1.1'];
        yield 'cgnat'               => ['100.64.0.1'];
        yield 'link-local/metadata' => ['169.254.169.254'];
        yield 'benchmark'           => ['198.18.0.1'];
        yield 'multicast'           => ['224.0.0.1'];
        yield 'broadcast'           => ['255.255.255.255'];
        yield 'v6 loopback'         => ['::1'];
        yield 'v6 unspecified'      => ['::'];
        yield 'v6 ula'              => ['fd00::1'];
        yield 'v6 link-local'       => ['fe80::1'];
        yield 'v6 mapped v4 loop'   => ['::ffff:127.0.0.1'];
        yield 'v6 mapped v4 priv'   => ['::ffff:10.1.2.3'];
        yield 'nat64 loopback'      => ['64:ff9b::7f00:1'];
        yield 'bracketed v6'        => ['[::1]'];
    }

    #[DataProvider('blockedIpLiterals')]
    public function test_cloud_mode_rejects_non_public_ip_literals(string $ip): void
    {
        self::assertNotNull($this->cloudPolicy()->rejectionReason($ip), $ip);
    }

    public function test_cloud_mode_accepts_public_ip_literals(): void
    {
        $policy = $this->cloudPolicy();

        self::assertNull($policy->rejectionReason('93.184.216.34'));
        self::assertNull($policy->rejectionReason('2606:2800:220:1:248:1893:25c8:1946'));
        self::assertNull($policy->rejectionReason('[2606:2800:220:1:248:1893:25c8:1946]'));
    }

    public function test_cloud_mode_rejects_internal_style_hostnames_without_resolving(): void
    {
        $resolved = [];
        $policy   = new SmtpHostPolicy(true, static function (string $host) use (&$resolved): array {
            $resolved[] = $host;
            return ['93.184.216.34'];
        });

        foreach (['localhost', 'mail', 'smtp.localhost', 'relay.local', 'db.internal', 'nas.lan', 'x.home', 'foo.corp', 'a.intranet', 'bad host.example.com'] as $host) {
            self::assertNotNull($policy->rejectionReason($host), $host);
        }
        self::assertSame([], $resolved, 'internal-style names must be rejected before any DNS lookup');
    }

    public function test_cloud_mode_rejects_public_hostname_resolving_to_private_address(): void
    {
        $policy = $this->cloudPolicy([
            'rebind.example.com' => ['93.184.216.34', '10.0.0.1'],
            'loop.example.com'   => ['127.0.0.1'],
            'meta.example.com'   => ['169.254.169.254'],
            'v6.example.com'     => ['fd12::1'],
        ]);

        foreach (['rebind.example.com', 'loop.example.com', 'meta.example.com', 'v6.example.com'] as $host) {
            self::assertNotNull($policy->rejectionReason($host), $host);
        }
    }

    public function test_cloud_mode_rejects_unresolvable_hostname(): void
    {
        self::assertNotNull($this->cloudPolicy()->rejectionReason('nx.example.com'));
    }

    public function test_cloud_mode_accepts_public_hostname(): void
    {
        $policy = $this->cloudPolicy(['smtp.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']]);

        self::assertNull($policy->rejectionReason('smtp.example.com'));
        self::assertNull($policy->rejectionReason('SMTP.example.com.'));
    }
}
