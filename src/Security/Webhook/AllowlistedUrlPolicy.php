<?php

declare(strict_types=1);

namespace Votepit\Security\Webhook;

/**
 * Strict allowlist check for outbound URLs that must point at ONE known
 * third-party service (Slack/Discord incoming webhooks today; reusable for
 * any future vendor integration with a fixed API host).
 *
 * Complements WebhookUrlGuard rather than replacing it: WebhookUrlGuard
 * answers "does this resolve to a public IP?" for arbitrary tenant URLs;
 * this policy answers "is this exactly the vendor endpoint shape we
 * expect?". Callers use both — this one at storage AND delivery time, the
 * guard at delivery time for the IP pin (DNS-rebinding defense).
 *
 * Deliberately narrow (SSRF, OWASP A01): https only, exact host match (no
 * subdomains, no trailing dot), no explicit port, no userinfo, no query,
 * no fragment, and the path must match the vendor-specific pattern. As a
 * final defense against URL-parser differentials the URL must equal its
 * own canonical rebuild "https://<host><path>" — anything parse_url()
 * might interpret differently from curl (backslashes, encoded bytes,
 * whitespace) simply fails that comparison.
 */
final readonly class AllowlistedUrlPolicy
{
    private const MAX_URL_LENGTH = 2048;

    /**
     * @param list<string> $hosts exact, lowercase host names
     * @param non-empty-string $pathPattern PCRE the URL path must fully match
     */
    public function __construct(
        private array $hosts,
        private string $pathPattern,
    ) {}

    public function allows(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'], $parsed['path'])) {
            return false;
        }
        if (isset($parsed['port']) || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return false;
        }
        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parsed['host']);
        if (!in_array($host, $this->hosts, true)) {
            return false;
        }

        $path = $parsed['path'];
        if (preg_match($this->pathPattern, $path) !== 1) {
            return false;
        }

        return strtolower($url) === strtolower('https://' . $host . $path);
    }
}
