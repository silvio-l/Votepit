<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Rate-limit identity for the guest buckets (ADR 0006 §4): IPv4 as-is,
 * IPv6 grouped by its /64 (one end-user line typically owns a whole /64,
 * so per-address keys would be trivially rotated). Existing buckets keep
 * their raw-IP keys; this is only used where the ADR asks for it.
 * Null for anything that is not a valid IP — the caller rejects (fail-secure).
 */
final class IpRateKey
{
    private function __construct() {}

    public static function of(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            return $ip;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is one IPv4 client.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $v4 = inet_ntop(substr($packed, 12));

            return $v4 === false ? null : $v4;
        }

        $prefix = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));

        return $prefix === false ? null : $prefix . '/64';
    }
}
