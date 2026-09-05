<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Outbound SMTP target policy (security review — SSRF / internal port
 * probing, OWASP A01 SSRF, ASVS V5.2.6).
 *
 * Tenant admins may configure a per-board SMTP relay and trigger test mails
 * against it. On the shared cloud host that would let any paying customer
 * point the server's SMTP client at loopback / RFC1918 / link-local /
 * cloud-metadata addresses and probe services that are not reachable from
 * the outside (success vs. failure and timing leak through the test
 * endpoint, and `verify_peer=0` + STARTTLS make it a general TCP dialer).
 *
 * Policy:
 *   - self-host (default): permissive — `localhost`/LAN relays are the normal
 *     setup for a single-tenant install and the admin owns the host anyway.
 *   - cloud (`routing_mode: cloud`): the target must be a public hostname or
 *     public IP. IP literals are checked directly; hostnames are resolved
 *     (A + AAAA) and every answer must be public; unresolvable, single-label
 *     and `*.localhost`/`*.local`/`*.internal` names are rejected.
 *
 * Known residual: resolution happens at validation time, not at connect
 * time (DNS rebinding TOCTOU). Closing that fully needs connect-time pinning
 * inside the mail transport and is out of scope here; the check still
 * removes the trivial `127.0.0.1`/`10.x`/`169.254.169.254` class entirely.
 */
final readonly class SmtpHostPolicy
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /**
     * @param bool $restrictToPublicTargets true = cloud mode policy active
     * @param null|\Closure(string): list<string> $resolver hostname → IP list
     *        (injectable for tests; default: DNS A + AAAA lookup)
     */
    public function __construct(
        private bool $restrictToPublicTargets,
        ?\Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? $this->dnsResolver();
    }

    public static function permissive(): self
    {
        return new self(false);
    }

    public function isRestricted(): bool
    {
        return $this->restrictToPublicTargets;
    }

    /**
     * Returns a user-facing rejection reason (matching the surrounding
     * validation messages), or null when the host is acceptable.
     */
    public function rejectionReason(string $host): ?string
    {
        $host = trim($host);
        if ($host === '') {
            return 'Host must not be empty.';
        }
        if (!$this->restrictToPublicTargets) {
            return null;
        }

        $bare = $this->stripBrackets($host);

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($bare) ? null : 'Host must be a publicly reachable mail server.';
        }

        $name = strtolower(rtrim($bare, '.'));
        if (!$this->isPlausiblePublicHostname($name)) {
            return 'Host must be a public DNS name (with a domain) or a public IP address.';
        }

        $ips = ($this->resolver)($name);
        if ($ips === []) {
            return 'Host cannot be resolved.';
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return 'Host must be a publicly reachable mail server.';
            }
        }

        return null;
    }

    private function stripBrackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }
        return $host;
    }

    /** @param string $lower already lower-cased, without trailing dot */
    private function isPlausiblePublicHostname(string $lower): bool
    {
        if ($lower === '' || strlen($lower) > 253) {
            return false;
        }
        if (filter_var($lower, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }
        // Single-label names resolve via the host's search domain (internal).
        if (!str_contains($lower, '.')) {
            return false;
        }
        foreach (['.localhost', '.local', '.internal', '.lan', '.home', '.corp', '.intranet'] as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return false;
            }
        }
        return $lower !== 'localhost';
    }

    /** Public = not loopback, private, link-local, CGNAT, multicast, reserved, unspecified. */
    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96) embed an
            // IPv4 address — judge the embedded address, not the wrapper.
            if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")
                || str_starts_with($packed, "\x00\x64\xff\x9b\0\0\0\0\0\0\0\0")) {
                return self::isPublicIpv4(substr($packed, 12));
            }
            return self::isPublicIpv6($packed);
        }

        return self::isPublicIpv4($packed);
    }

    private static function isPublicIpv4(string $packed): bool
    {
        $unpacked = unpack('C4', $packed);
        if ($unpacked === false || count($unpacked) !== 4) {
            return false;
        }
        $b = array_values($unpacked);
        [$a, $b2] = [$b[0], $b[1]];
        return !(
            in_array($a, [0, 10, 127], true)           // 0/8 this-network, 10/8, 127/8 loopback
            || ($a === 100 && $b2 >= 64 && $b2 <= 127) // 100.64/10 CGNAT
            || ($a === 169 && $b2 === 254)             // link-local, cloud metadata
            || ($a === 172 && $b2 >= 16 && $b2 <= 31)  // 172.16/12
            || ($a === 192 && $b2 === 168)             // 192.168/16
            || ($a === 192 && $b2 === 0 && $b[2] === 0) // 192.0.0/24 IETF protocol assignments
            || ($a === 198 && ($b2 === 18 || $b2 === 19)) // 198.18/15 benchmarking
            || $a >= 224                               // multicast + reserved + broadcast
        );
    }

    private static function isPublicIpv6(string $packed): bool
    {
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        if ($packed === str_repeat("\0", 16)) {
            return false; // ::
        }
        if ($packed === str_repeat("\0", 15) . "\x01") {
            return false; // ::1
        }
        if (($first & 0xfe) === 0xfc) {
            return false; // fc00::/7 unique local
        }
        if ($first === 0xfe && ($second & 0xc0) === 0x80) {
            return false; // fe80::/10 link-local
        }
        if ($first === 0xfe && ($second & 0xc0) === 0xc0) {
            return false; // fec0::/10 deprecated site-local
        }
        if ($first === 0xff) {
            return false; // multicast
        }
        if ($first === 0x20 && $second === 0x01 && ord($packed[2]) === 0x0d && ord($packed[3]) === 0xb8) {
            return false; // 2001:db8::/32 documentation
        }
        return true;
    }

    /** @return \Closure(string): list<string> */
    private function dnsResolver(): \Closure
    {
        return static function (string $host): array {
            $ips = [];
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            if ($ips === []) {
                // dns_get_record may be unavailable/blocked on some shared
                // hosts — fall back to the resolver library for A records.
                $v4 = gethostbyname($host);
                if ($v4 !== $host) {
                    $ips[] = $v4;
                }
            }
            return $ips;
        };
    }
}
