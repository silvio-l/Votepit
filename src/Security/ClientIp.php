<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Single-source-of-truth client-IP resolution.
 *
 * Once a Cloudflare proxy sits in front of the app origin, every request's
 * REMOTE_ADDR becomes Cloudflare's edge IP, not the real client — silently
 * breaking IP-based rate-limiting (every visitor would share one bucket) and
 * any IP recorded in audit logs. Cloudflare forwards the real client IP in the
 * `CF-Connecting-IP` header.
 *
 * That header is trivially spoofable by anyone who can reach the origin
 * directly, so it is used ONLY when $trustCloudflareIp is true — which must
 * only ever be enabled together with an origin-lock (vhost/firewall accepts
 * connections only from Cloudflare's published IP ranges — a real-infra
 * task, not covered by this class). Fail-secure default: false → REMOTE_ADDR only,
 * identical to the previous behavior. This mirrors RateLimitMiddleware's
 * existing fail-open-for-availability / fail-secure-for-everything-else split
 * (RateLimitMiddleware doc comment): trusting an unverifiable header by
 * default would be the wrong direction for a security-relevant IP signal.
 */
final class ClientIp
{
    private function __construct() {}

    public static function resolve(ServerRequestInterface $request, bool $trustCloudflareIp): ?string
    {
        if ($trustCloudflareIp) {
            $headers = $request->getHeader('CF-Connecting-IP');
            $header  = trim($headers[0] ?? '');
            if ($header !== '' && filter_var($header, FILTER_VALIDATE_IP) !== false) {
                return $header;
            }
        }

        $params = $request->getServerParams();
        $ip     = $params['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
