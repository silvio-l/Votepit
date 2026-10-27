<?php

declare(strict_types=1);

namespace Votepit\Security\Webhook;

use Votepit\Security\SmtpHostPolicy;

/**
 * SSRF guard for tenant-configured outbound webhook URLs (board-webhooks
 * feature, OWASP A01 SSRF).
 *
 * Unlike SmtpHostPolicy (permissive by default on self-host — a local relay
 * is a legitimate self-host setup and the admin owns the host anyway), this
 * guard is ALWAYS restrictive, in both self-host and cloud mode: a webhook
 * target is chosen by a board owner/admin, not the installation operator,
 * and self-host installs sit on the same host/LAN as internal services
 * (e.g. the DB, an internal admin panel) that a malicious or compromised
 * board owner could otherwise reach through the server. There is no
 * legitimate "point the webhook at localhost" use case.
 *
 * Reuses SmtpHostPolicy::isPublicIp() as the single source of truth for
 * "which IP ranges are private/reserved/loopback/link-local" — the range
 * table must not fork between the two SSRF guards in this codebase.
 *
 * Two-phase use, both going through the SAME hostname→IP resolution+range
 * check (defense in depth, ADR — see the security requirements this class
 * implements):
 *   - validateForStorage(): called when an admin saves the webhook URL.
 *   - resolveForDelivery(): called again immediately before every actual
 *     HTTP request (including every redirect hop) — DNS-rebinding
 *     resistant, because the resolved IP from THIS call is what the HTTP
 *     client is pinned to (WebhookTarget), not re-resolved by curl itself.
 */
final readonly class WebhookUrlGuard
{
    private const MAX_URL_LENGTH = 2048;

    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /**
     * @param null|\Closure(string): list<string> $resolver hostname → IP list
     *        (injectable for tests; default: DNS A + AAAA lookup, same
     *        strategy as SmtpHostPolicy's default resolver).
     */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? $this->dnsResolver();
    }

    /**
     * Validates a URL for saving as a board's webhook target. Returns a
     * user-facing rejection reason, or null when acceptable.
     */
    public function validateForStorage(string $url): ?string
    {
        $shapeError = $this->checkShape($url);
        if ($shapeError !== null) {
            return $shapeError;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        return $this->resolvePublicIps($host) === []
            ? 'Webhook URL must resolve to a publicly reachable address.'
            : null;
    }

    /**
     * Re-validates AND pins the URL to one resolved public IP, for a single
     * actual delivery attempt (or redirect hop). Returns null when the URL
     * is malformed, non-https, or resolves to (any) non-public address —
     * callers MUST treat null as "do not send", never fall back to sending
     * unpinned.
     */
    public function resolveForDelivery(string $url): ?WebhookTarget
    {
        if ($url === '' || $this->checkShape($url) !== null) {
            return null;
        }

        $parsed = parse_url($url);
        $host   = is_array($parsed) && is_string($parsed['host'] ?? null) ? $parsed['host'] : '';
        $port   = is_array($parsed) && is_int($parsed['port'] ?? null) ? $parsed['port'] : 443;

        $ips = $this->resolvePublicIps($host);
        if ($ips === []) {
            return null;
        }

        return new WebhookTarget($url, $host, $port, $ips[0]);
    }

    /**
     * Structural checks that don't require any network access: scheme,
     * shape, no embedded credentials, URL length.
     */
    private function checkShape(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return 'Webhook URL is missing or too long.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'Webhook URL is malformed.';
        }
        $scheme = $parsed['scheme'];
        $host   = $parsed['host'];
        if (strtolower($scheme) !== 'https') {
            return 'Webhook URL must use https://.';
        }
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return 'Webhook URL must not contain embedded credentials.';
        }
        if ($host === '') {
            return 'Webhook URL is missing a host.';
        }

        return null;
    }

    /**
     * Resolves a (shape-already-valid) host to its list of public IPs.
     * Empty list = blocked (unresolvable, single-label, internal-only
     * suffix, or ANY resolved answer lands in a private/reserved range —
     * one bad answer blocks the whole hostname rather than cherry-picking
     * only the public ones, which would otherwise be trivially defeated by
     * a DNS response carrying both a public and a private A record).
     *
     * @return list<string>
     */
    private function resolvePublicIps(string $host): array
    {
        $bare = $this->stripBrackets($host);

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return SmtpHostPolicy::isPublicIp($bare) ? [$bare] : [];
        }

        $lower = strtolower(rtrim($bare, '.'));
        if ($lower === '' || strlen($lower) > 253 || !str_contains($lower, '.')) {
            // Single-label names resolve via the host's search domain
            // (internal) — never a legitimate public webhook target.
            return [];
        }
        if (filter_var($lower, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return [];
        }
        foreach (['.localhost', '.local', '.internal', '.lan', '.home', '.corp', '.intranet'] as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return [];
            }
        }

        $ips = ($this->resolver)($lower);
        if ($ips === []) {
            return [];
        }
        foreach ($ips as $ip) {
            if (!SmtpHostPolicy::isPublicIp($ip)) {
                return [];
            }
        }

        return $ips;
    }

    private function stripBrackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }
        return $host;
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
                $v4 = gethostbyname($host);
                if ($v4 !== $host) {
                    $ips[] = $v4;
                }
            }
            return $ips;
        };
    }
}
