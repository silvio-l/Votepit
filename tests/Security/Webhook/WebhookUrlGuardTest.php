<?php

declare(strict_types=1);

namespace Votepit\Tests\Security\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\Webhook\WebhookUrlGuard;

/**
 * Security review — SSRF guard for tenant-configured outbound webhook URLs
 * (board-webhooks feature). The single most security-critical unit in this
 * feature: any gap here lets a malicious/compromised board owner make the
 * server issue requests against internal/cloud-metadata targets.
 *
 * DNS is stubbed through the injectable resolver; no test touches the
 * network. Mirrors SmtpHostPolicyTest's IP-range coverage (both guards
 * share SmtpHostPolicy::isPublicIp() as the single range table), plus
 * webhook-specific checks (scheme, embedded credentials, resolveForDelivery
 * pinning).
 */
final class WebhookUrlGuardTest extends TestCase
{
    /** @param array<string, list<string>> $table */
    private function guard(array $table = []): WebhookUrlGuard
    {
        return new WebhookUrlGuard(static fn (string $host): array => $table[strtolower($host)] ?? []);
    }

    // ── Scheme / shape ──────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function malformedOrNonHttpsUrls(): iterable
    {
        yield 'empty'                => [''];
        yield 'http scheme'          => ['http://example.com/hook'];
        yield 'ftp scheme'           => ['ftp://example.com/hook'];
        yield 'file scheme'          => ['file:///etc/passwd'];
        yield 'gopher scheme'        => ['gopher://example.com/hook'];
        yield 'no scheme'            => ['example.com/hook'];
        yield 'malformed'            => ['https:///no-host'];
        yield 'embedded credentials' => ['https://user:pass@example.com/hook'];
        yield 'too long'             => ['https://example.com/' . str_repeat('a', 2100)];
    }

    #[DataProvider('malformedOrNonHttpsUrls')]
    public function test_rejects_malformed_or_non_https_urls_for_storage(string $url): void
    {
        self::assertNotNull($this->guard()->validateForStorage($url));
    }

    #[DataProvider('malformedOrNonHttpsUrls')]
    public function test_rejects_malformed_or_non_https_urls_for_delivery(string $url): void
    {
        self::assertNull($this->guard()->resolveForDelivery($url));
    }

    // ── IP literal targets ──────────────────────────────────────────────

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
        yield 'link-local/metadata' => ['169.254.169.254']; // cloud metadata endpoint
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
    }

    #[DataProvider('blockedIpLiterals')]
    public function test_rejects_blocked_ip_literal_for_storage(string $ip): void
    {
        self::assertNotNull($this->guard()->validateForStorage("https://{$ip}/hook"));
    }

    #[DataProvider('blockedIpLiterals')]
    public function test_rejects_blocked_ip_literal_for_delivery(string $ip): void
    {
        self::assertNull($this->guard()->resolveForDelivery("https://{$ip}/hook"));
    }

    public function test_rejects_bracketed_v6_loopback_ip_literal(): void
    {
        self::assertNotNull($this->guard()->validateForStorage('https://[::1]/hook'));
        self::assertNull($this->guard()->resolveForDelivery('https://[::1]/hook'));
    }

    public function test_accepts_public_ip_literal(): void
    {
        $guard = $this->guard();
        self::assertNull($guard->validateForStorage('https://93.184.216.34/hook'));

        $target = $guard->resolveForDelivery('https://93.184.216.34/hook');
        self::assertNotNull($target);
        self::assertSame('93.184.216.34', $target->ip);
        self::assertSame(443, $target->port);
    }

    // ── Hostname targets ─────────────────────────────────────────────────

    public function test_rejects_internal_style_hostnames_without_resolving(): void
    {
        $resolved = [];
        $guard    = new WebhookUrlGuard(static function (string $host) use (&$resolved): array {
            $resolved[] = $host;
            return ['93.184.216.34'];
        });

        foreach (['localhost', 'hook', 'relay.localhost', 'svc.local', 'db.internal', 'nas.lan', 'x.home', 'foo.corp', 'a.intranet'] as $host) {
            self::assertNotNull($guard->validateForStorage("https://{$host}/hook"), $host);
        }
        self::assertSame([], $resolved, 'internal-style names must be rejected before any DNS lookup');
    }

    public function test_rejects_hostname_resolving_to_any_private_address(): void
    {
        $guard = $this->guard([
            'rebind.example.com' => ['93.184.216.34', '10.0.0.1'], // mixed public+private: whole host blocked
            'loop.example.com'   => ['127.0.0.1'],
            'meta.example.com'   => ['169.254.169.254'],
            'v6.example.com'     => ['fd12::1'],
        ]);

        foreach (['rebind.example.com', 'loop.example.com', 'meta.example.com', 'v6.example.com'] as $host) {
            self::assertNotNull($guard->validateForStorage("https://{$host}/hook"), $host);
            self::assertNull($guard->resolveForDelivery("https://{$host}/hook"), $host);
        }
    }

    public function test_rejects_unresolvable_hostname(): void
    {
        self::assertNotNull($this->guard()->validateForStorage('https://nx.example.com/hook'));
    }

    public function test_accepts_public_hostname_and_pins_first_resolved_ip(): void
    {
        $guard = $this->guard(['hooks.example.com' => ['93.184.216.34', '203.0.113.9']]);

        self::assertNull($guard->validateForStorage('https://hooks.example.com/hook'));

        $target = $guard->resolveForDelivery('https://hooks.example.com:8443/hook?x=1');
        self::assertNotNull($target);
        self::assertSame('hooks.example.com', $target->host);
        self::assertSame(8443, $target->port);
        self::assertSame('93.184.216.34', $target->ip);
        self::assertSame('https://hooks.example.com:8443/hook?x=1', $target->url);
    }

    // ── DNS-rebinding: resolution happens fresh on every delivery call ─────

    public function test_resolve_for_delivery_re_resolves_every_call_not_cached(): void
    {
        /** @var list<string> $answers */
        $answers = ['93.184.216.34']; // first call: public

        /** @var \Closure(string): list<string> $resolver */
        $resolver = static function (string $host) use (&$answers): array {
            return $answers;
        };
        $guard = new WebhookUrlGuard($resolver);

        $first = $guard->resolveForDelivery('https://rebinder.example.com/hook');
        self::assertNotNull($first);

        $answers = ['10.0.0.1']; // attacker rebinds DNS to a private address before the next attempt
        $second = $guard->resolveForDelivery('https://rebinder.example.com/hook');
        self::assertNull($second, 'a rebound-to-private answer must be blocked on the very next resolution');
    }
}
