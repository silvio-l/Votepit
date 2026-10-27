<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;

/**
 * GET /api/v1/session/badge — the minimal signed-in identity needed to render
 * a header profile badge (avatar/initials, display name, links into the app).
 *
 * Reads only the user that AuthNMiddleware already hydrated for this request.
 * It never queries the database itself, never touches a session cookie
 * directly, and therefore cannot create or extend a session: the response is
 * marked for cookie stripping (SecurityHeaderMiddleware::INTERNAL_COOKIELESS),
 * which also drops the sliding-inactivity reissue AuthNMiddleware would
 * otherwise perform for a stale `lact` claim.
 *
 * Response (signed out, blocked, or any failure while building the body):
 *   {"authenticated":false}
 * Response (signed in):
 *   {"authenticated":true,"displayName","initials","avatarUrl","appUrl","profileUrl"}
 * Deliberately absent: email (HMAC-only anyway), user/account IDs, roles,
 * memberships, account or board lists — the badge needs none of them.
 *
 * Cross-origin reads are granted separately by
 * CredentialedCorsAllowlistMiddleware (exact-origin allowlist, default empty).
 */
final readonly class SessionBadgeAction
{
    public function __construct(private Config $config) {}

    public function badge(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            $payload = is_array($user) ? $this->signedInPayload($user) : null;
        } catch (\Throwable) {
            // Fail-secure: an unexpected failure while shaping the badge
            // reads as signed out, never as an error that leaks state.
            $payload = null;
        }

        $body = $payload ?? ['authenticated' => false];

        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Vary', 'Origin, Cookie')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader(SecurityHeaderMiddleware::INTERNAL_COOKIELESS, '1');
    }

    /**
     * @param array<string, mixed> $user the hydrated users row (AuthNMiddleware::ATTR_USER)
     * @return array{authenticated: true, displayName: string, initials: string, avatarUrl: ?string, appUrl: string, profileUrl: string}|null
     */
    private function signedInPayload(array $user): ?array
    {
        // A globally blocked account gets the logged-out UI. Reads are not
        // blocked by BlockCheckMiddleware, so this is the one place a blocked
        // user would otherwise be shown as signed in.
        if ((bool) ($user['is_blocked'] ?? false)) {
            return null;
        }

        // Same precedence as the SPA header (LocalizedHeader): username first,
        // opaque public_id as the fallback.
        $username    = $user['username'] ?? null;
        $username    = is_string($username) ? trim($username) : '';
        $publicId    = $user['public_id'] ?? null;
        $publicId    = is_string($publicId) ? $publicId : '';
        $displayName = $username !== '' ? $username : $publicId;
        if ($displayName === '') {
            return null;
        }

        $avatar    = $user['avatar_filename'] ?? null;
        $avatarUrl = is_string($avatar) && $avatar !== ''
            ? $this->config->appUrl . '/avatar/' . rawurlencode($avatar)
            : null;

        return [
            'authenticated' => true,
            'displayName'   => $displayName,
            'initials'      => $this->initials($displayName),
            'avatarUrl'     => $avatarUrl,
            'appUrl'        => $this->config->appUrl,
            'profileUrl'    => $this->config->appUrl . '/account/profile',
        ];
    }

    /**
     * Up to two uppercase letters: the first letter of the first two words
     * ("jane_doe" → "JD", "silvio lindstedt" → "SL"), or the first two
     * characters of a single word ("jane" → "JA").
     */
    private function initials(string $displayName): string
    {
        $parts = preg_split('/[\s._-]+/u', $displayName, -1, PREG_SPLIT_NO_EMPTY);
        $parts = is_array($parts) ? $parts : [];

        $initials = count($parts) >= 2
            ? mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1)
            : mb_substr($displayName, 0, 2);

        return mb_strtoupper($initials);
    }
}
