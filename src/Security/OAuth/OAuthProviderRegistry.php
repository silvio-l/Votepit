<?php

declare(strict_types=1);

namespace Votepit\Security\OAuth;

/**
 * Fixed allowlist of OAuth2 providers this codebase implements, plus their
 * HARD-CODED endpoint constants.
 *
 * Every URL below is a fixed literal, never built from user/request input —
 * there is no SSRF vector here (OAuthStartAction/OAuthCallbackAction only
 * ever pick ONE of these constant sets by validating `{provider}` against
 * ::isKnown() first; nothing in the URL is interpolated from the request).
 *
 * PKCE (RFC 7636): Google supports and is configured for it here
 * (defense in depth for a public/confidential mixed client on a shared
 * origin); GitHub's classic OAuth Apps flow does not, so it stays
 * state-only — this codebase does not implement GitHub Apps' newer
 * device/PKCE flows.
 */
final class OAuthProviderRegistry
{
    /** @var list<string> */
    public const PROVIDERS = ['google', 'github'];

    /**
     * @var array<string, array{
     *   authorize: string,
     *   token: string,
     *   userinfo: string,
     *   emails: string|null,
     *   scope: string,
     *   pkce: bool,
     * }>
     */
    private const ENDPOINTS = [
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token'     => 'https://oauth2.googleapis.com/token',
            'userinfo'  => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'emails'    => null,
            'scope'     => 'openid email',
            'pkce'      => true,
        ],
        'github' => [
            'authorize' => 'https://github.com/login/oauth/authorize',
            'token'     => 'https://github.com/login/oauth/access_token',
            'userinfo'  => 'https://api.github.com/user',
            // GitHub's /user email field can be null/private — the primary
            // verified address is fetched separately from this endpoint.
            'emails'    => 'https://api.github.com/user/emails',
            'scope'     => 'read:user user:email',
            'pkce'      => false,
        ],
    ];

    public static function isKnown(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    /**
     * @return array{authorize: string, token: string, userinfo: string, emails: string|null, scope: string, pkce: bool}
     */
    public static function endpoints(string $provider): array
    {
        if (!self::isKnown($provider)) {
            throw new \InvalidArgumentException("oauth: unknown provider \"{$provider}\"");
        }

        return self::ENDPOINTS[$provider];
    }
}
