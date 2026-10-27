<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

use Votepit\Security\ApiTokenScope;

/**
 * The MCP OAuth server's public URLs and discovery documents, all derived
 * from Config::$appUrl (the installation's own origin — issuer, authorization
 * server and protected resource are the same origin; nothing is taken from
 * the request's Host header).
 *
 *  - Authorization server metadata (RFC 8414) at
 *    /.well-known/oauth-authorization-server.
 *  - Protected resource metadata (RFC 9728) for the MCP endpoint at
 *    /.well-known/oauth-protected-resource/api/v1/mcp (path-suffixed form,
 *    advertised in the MCP endpoint's 401 WWW-Authenticate header) and at
 *    the bare /.well-known/oauth-protected-resource for clients that probe
 *    the root.
 */
final readonly class OAuthServerMetadata
{
    public const MCP_PATH = '/api/v1/mcp';

    private string $issuer;

    public function __construct(string $appUrl)
    {
        $this->issuer = rtrim($appUrl, '/');
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    /** The protected resource: the MCP endpoint (RFC 8707 resource indicator). */
    public function resource(): string
    {
        return $this->issuer . self::MCP_PATH;
    }

    public function resourceMetadataUrl(): string
    {
        return $this->issuer . '/.well-known/oauth-protected-resource' . self::MCP_PATH;
    }

    /**
     * True if a client-sent RFC 8707 `resource` names this MCP endpoint (or
     * the issuer itself). A query string (e.g. `?board=<slug>`) is ignored —
     * the board is chosen per request, not per token audience.
     */
    public function acceptsResource(string $resource): bool
    {
        $withoutQuery = explode('?', $resource, 2)[0];
        $normalized   = rtrim($withoutQuery, '/');

        return $normalized === $this->resource() || $normalized === $this->issuer;
    }

    /** @return array<string, mixed> RFC 8414 document */
    public function authorizationServerDocument(): array
    {
        return [
            'issuer'                                         => $this->issuer,
            'authorization_endpoint'                         => $this->issuer . '/oauth/authorize',
            'token_endpoint'                                 => $this->issuer . '/oauth/token',
            'registration_endpoint'                          => $this->issuer . '/oauth/register',
            'revocation_endpoint'                            => $this->issuer . '/oauth/revoke',
            'response_types_supported'                       => ['code'],
            'response_modes_supported'                       => ['query'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => ['S256'],
            'token_endpoint_auth_methods_supported'          => ['none'],
            'revocation_endpoint_auth_methods_supported'     => ['none'],
            'scopes_supported'                               => ApiTokenScope::ALL,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /** @return array<string, mixed> RFC 9728 document */
    public function protectedResourceDocument(): array
    {
        return [
            'resource'                 => $this->resource(),
            'authorization_servers'    => [$this->issuer],
            'scopes_supported'         => ApiTokenScope::ALL,
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'Votepit MCP',
        ];
    }
}
