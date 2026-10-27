<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

/**
 * A validated OAuth authorization request (AuthorizationRequestValidator):
 * the registered client, one of its registered redirect URIs (exact match),
 * an S256 code challenge, the opaque client `state` and the highest grant
 * level the client asked for (OAuthScope::maxLevel()).
 */
final readonly class AuthorizationRequest
{
    /**
     * @param array{id: int, client_id: string, client_name: string, redirect_uris: list<string>} $client
     */
    public function __construct(
        public array $client,
        public string $redirectUri,
        public string $codeChallenge,
        public ?string $state,
        public string $maxScope,
        private string $issuer,
    ) {}

    /** Redirect carrying the issued code (RFC 6749 §4.1.2 + RFC 9207 `iss`). */
    public function successRedirect(string $code): string
    {
        return self::appendQuery($this->redirectUri, ['code' => $code, 'state' => $this->state, 'iss' => $this->issuer]);
    }

    /** Redirect carrying an error back to the client (RFC 6749 §4.1.2.1). */
    public function errorRedirect(string $error, string $description): string
    {
        return self::errorRedirectFor($this->redirectUri, $this->state, $this->issuer, $error, $description);
    }

    public static function errorRedirectFor(string $redirectUri, ?string $state, string $issuer, string $error, string $description): string
    {
        return self::appendQuery($redirectUri, [
            'error'             => $error,
            'error_description' => $description,
            'state'             => $state,
            'iss'               => $issuer,
        ]);
    }

    /** @param array<string, string|null> $params null values are left out */
    private static function appendQuery(string $uri, array $params): string
    {
        $query = http_build_query(array_filter($params, static fn (?string $v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);

        return $uri . (str_contains($uri, '?') ? '&' : '?') . $query;
    }
}
