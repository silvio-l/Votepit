<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

/**
 * Validates an RFC 7591 dynamic client registration request — deliberately
 * restricted to what an MCP client needs:
 *
 *  - public clients only: token_endpoint_auth_method absent or `none`, no
 *    client secret is ever issued;
 *  - grant_types ⊆ {authorization_code, refresh_token}, response_types ⊆ {code};
 *  - 1–MAX_REDIRECT_URIS redirect URIs, each allowed by RedirectUriPolicy;
 *  - client_name: optional plaintext, at most NAME_MAX_LENGTH characters, no
 *    control characters. It is shown on the consent screen and the token
 *    page — always as escaped text, never as markup or a link (shared-origin
 *    invariant). Absent → DEFAULT_NAME.
 *
 * Every other metadata field (logo_uri, client_uri, contacts, …) is ignored:
 * not stored, not echoed, never rendered — the consent screen shows only
 * the name and the redirect target, so a registration cannot smuggle in
 * remote images or links.
 */
final class ClientRegistrationValidator
{
    public const NAME_MAX_LENGTH   = 100;
    public const MAX_REDIRECT_URIS = 5;
    public const DEFAULT_NAME      = 'MCP client';

    /**
     * @return array{client_name: string, redirect_uris: non-empty-list<string>}|array{error: string, error_description: string}
     */
    public static function validate(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return self::error('invalid_client_metadata', 'The registration request must be a JSON object.');
        }

        $authMethod = $metadata['token_endpoint_auth_method'] ?? 'none';
        if ($authMethod !== 'none') {
            return self::error('invalid_client_metadata', 'Only public clients are supported (token_endpoint_auth_method "none").');
        }

        if (!self::isSubsetOf($metadata['grant_types'] ?? null, ['authorization_code', 'refresh_token'])) {
            return self::error('invalid_client_metadata', 'Supported grant_types: authorization_code, refresh_token.');
        }

        if (!self::isSubsetOf($metadata['response_types'] ?? null, ['code'])) {
            return self::error('invalid_client_metadata', 'Supported response_types: code.');
        }

        $rawUris = $metadata['redirect_uris'] ?? null;
        if (!is_array($rawUris) || $rawUris === [] || !array_is_list($rawUris) || count($rawUris) > self::MAX_REDIRECT_URIS) {
            return self::error('invalid_redirect_uri', 'Between 1 and ' . self::MAX_REDIRECT_URIS . ' redirect_uris are required.');
        }

        $uris = [];
        foreach ($rawUris as $uri) {
            if (!is_string($uri) || !RedirectUriPolicy::isAllowed($uri)) {
                return self::error('invalid_redirect_uri', 'Redirect URIs must use https, or http on a loopback host (127.0.0.1, localhost, [::1]).');
            }
            $uris[] = $uri;
        }

        $rawName = $metadata['client_name'] ?? null;
        if ($rawName !== null && !is_string($rawName)) {
            return self::error('invalid_client_metadata', 'client_name must be a string.');
        }

        $name = trim((string) $rawName);
        if ($name !== '' && (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $name) !== 0 || mb_strlen($name) > self::NAME_MAX_LENGTH)) {
            return self::error('invalid_client_metadata', 'client_name must be plain text of at most ' . self::NAME_MAX_LENGTH . ' characters.');
        }

        return [
            'client_name'   => $name !== '' ? $name : self::DEFAULT_NAME,
            'redirect_uris' => array_values(array_unique($uris)),
        ];
    }

    /** @param list<string> $allowed */
    private static function isSubsetOf(mixed $values, array $allowed): bool
    {
        if ($values === null) {
            return true;
        }
        if (!is_array($values) || !array_is_list($values)) {
            return false;
        }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{error: string, error_description: string} */
    private static function error(string $error, string $description): array
    {
        return ['error' => $error, 'error_description' => $description];
    }
}
