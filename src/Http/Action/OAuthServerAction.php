<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Support\OAuthJson;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\OAuthClientRepository;
use Votepit\Security\OAuthServer\ClientRegistrationValidator;
use Votepit\Security\OAuthServer\OAuthServerMetadata;
use Votepit\Security\OAuthServer\OAuthTokenService;
use Votepit\Support\Clock;

/**
 * The anonymous machine endpoints of the MCP OAuth server (issue 21):
 *
 *  GET  /.well-known/oauth-authorization-server   RFC 8414 metadata
 *  GET  /.well-known/oauth-protected-resource[/api/v1/mcp]  RFC 9728 metadata
 *  POST /oauth/register   RFC 7591 dynamic client registration (public clients only)
 *  POST /oauth/token      authorization_code (PKCE S256) + refresh_token grants
 *  POST /oauth/revoke     RFC 7009 token revocation
 *
 * None of them carries a session: they are called by the MCP client, not
 * the browser. They are exempt from CSRF by route name (CsrfMiddleware —
 * no cookie, no ambient credential to forge) and rate-limited per IP (plus
 * a global cap on registrations). Client authentication is the public-client
 * `client_id` (OAuth 2.1 §2.4); proof of possession comes from PKCE and the
 * refresh-token rotation, not from a client secret.
 */
final readonly class OAuthServerAction
{
    /** Registration request bodies above this size are rejected outright. */
    private const MAX_REGISTRATION_BYTES = 8192;

    /** Clients without a grant or pending code are purged after this long. */
    private const UNUSED_CLIENT_TTL = '-7 days';

    public function __construct(
        private OAuthServerMetadata $metadata,
        private OAuthClientRepository $clients,
        private OAuthTokenService $tokenService,
        private Clock $clock,
        private AuditLogger $audit,
    ) {}

    public function authorizationServerMetadata(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->publicJson($response, $this->metadata->authorizationServerDocument());
    }

    public function protectedResourceMetadata(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->publicJson($response, $this->metadata->protectedResourceDocument());
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $size = $request->getBody()->getSize();
        if ($size === null || $size > self::MAX_REGISTRATION_BYTES) {
            return OAuthJson::error($response, 400, 'invalid_client_metadata', 'The registration request is too large.');
        }

        $result = ClientRegistrationValidator::validate($request->getParsedBody());
        if (isset($result['error'])) {
            return OAuthJson::error($response, 400, $result['error'], $result['error_description']);
        }

        $now      = $this->clock->now();
        $clientId = 'vpc_' . bin2hex(random_bytes(16));

        $this->clients->purgeUnused($now->modify(self::UNUSED_CLIENT_TTL));
        $this->clients->create($clientId, $result['client_name'], $result['redirect_uris'], $now);
        $this->audit->log('oauth.client_registered', ['client_id' => $clientId]);

        return OAuthJson::respond($response, 201, [
            'client_id'                  => $clientId,
            'client_id_issued_at'        => $now->getTimestamp(),
            'client_name'                => $result['client_name'],
            'redirect_uris'              => $result['redirect_uris'],
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    public function token(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $this->formParams($request);
        if ($params === null) {
            return OAuthJson::error($response, 400, 'invalid_request', 'The request must be application/x-www-form-urlencoded.');
        }

        $client = $this->authenticateClient($params);
        if ($client === null) {
            return OAuthJson::error($response, 401, 'invalid_client', 'Unknown client.');
        }

        $grantType = $params['grant_type'] ?? '';
        $result    = match ($grantType) {
            'authorization_code' => isset($params['code'], $params['code_verifier'], $params['redirect_uri'])
                ? $this->tokenService->exchangeCode($client, $params['code'], $params['code_verifier'], $params['redirect_uri'])
                : ['error' => 'invalid_request', 'error_description' => 'code, code_verifier and redirect_uri are required.'],
            'refresh_token' => isset($params['refresh_token'])
                ? $this->tokenService->refresh($client, $params['refresh_token'])
                : ['error' => 'invalid_request', 'error_description' => 'refresh_token is required.'],
            default => ['error' => 'unsupported_grant_type', 'error_description' => 'Supported: authorization_code, refresh_token.'],
        };

        return OAuthJson::respond($response, isset($result['error']) ? 400 : 200, $result);
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $this->formParams($request);
        if ($params === null || !isset($params['token'])) {
            return OAuthJson::error($response, 400, 'invalid_request', 'token is required (application/x-www-form-urlencoded).');
        }

        $client = $this->authenticateClient($params);
        if ($client === null) {
            return OAuthJson::error($response, 401, 'invalid_client', 'Unknown client.');
        }

        $this->tokenService->revoke($client, $params['token']);

        return OAuthJson::respond($response, 200, []);
    }

    /**
     * @param array<string, string> $params
     * @return array{id: int, client_id: string, client_name: string, redirect_uris: list<string>}|null
     */
    private function authenticateClient(array $params): ?array
    {
        $clientId = $params['client_id'] ?? '';

        return $clientId !== '' && strlen($clientId) <= 64 ? $this->clients->findByClientId($clientId) : null;
    }

    /**
     * Non-empty string parameters of a form-encoded body (RFC 6749 §3.2:
     * the token endpoint takes application/x-www-form-urlencoded only).
     * Array-valued or repeated parameters are dropped — never coerced.
     *
     * @return array<string, string>|null
     */
    private function formParams(ServerRequestInterface $request): ?array
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $body        = $request->getParsedBody();
        if ($contentType !== 'application/x-www-form-urlencoded' || !is_array($body)) {
            return null;
        }

        $params = [];
        foreach ($body as $name => $value) {
            if (is_string($name) && is_string($value) && $value !== '' && strlen($value) <= 2048) {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /** @param array<string, mixed> $document */
    private function publicJson(ResponseInterface $response, array $document): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($document, JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
