<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

use Votepit\Persistence\OAuthClientRepository;

/**
 * Validates the parameters of an authorization request (the consent
 * screen's context read AND its decision POST run through the same checks,
 * so nothing the consent screen displayed can be swapped on submit).
 *
 * Order matters (RFC 6749 §4.1.2.1): client_id and redirect_uri are checked
 * first and their errors are NEVER redirected; only once both are verified
 * (registered client, exact string match against its registered URIs) do
 * further errors go back to the client via the redirect URI.
 *
 * Then: response_type must be `code`; PKCE is mandatory and S256-only (a
 * missing challenge, `plain` or any other method → invalid_request); scope
 * values must be known grant levels (invalid_scope); an RFC 8707 resource,
 * if sent, must name this MCP endpoint (invalid_target); state is passed
 * through opaquely but bounded in length.
 */
final readonly class AuthorizationRequestValidator
{
    private const STATE_MAX_LENGTH = 512;

    public function __construct(
        private OAuthClientRepository $clients,
        private OAuthServerMetadata $metadata,
    ) {}

    /** @param array<array-key, mixed> $params */
    public function validate(array $params): AuthorizationRequest|AuthorizationError
    {
        $clientId    = $this->stringParam($params, 'client_id');
        $redirectUri = $this->stringParam($params, 'redirect_uri');

        $client = $clientId !== null && strlen($clientId) <= 64 ? $this->clients->findByClientId($clientId) : null;
        if ($client === null) {
            return new AuthorizationError('invalid_client', 'Unknown client.');
        }

        if ($redirectUri === null || !in_array($redirectUri, $client['redirect_uris'], true)) {
            return new AuthorizationError('invalid_redirect_uri', 'The redirect_uri does not match a URI registered for this client.');
        }

        $rawState = $params['state'] ?? null;
        $state    = is_string($rawState) && $rawState !== '' ? $rawState : null;
        if ($state !== null && (strlen($state) > self::STATE_MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $state) === 1)) {
            // A bad state is not echoed back — the client gets the error without it.
            return $this->redirectError($redirectUri, null, 'invalid_request', 'The state parameter is invalid.');
        }

        if ($this->stringParam($params, 'response_type') !== 'code') {
            return $this->redirectError($redirectUri, $state, 'unsupported_response_type', 'Only response_type=code is supported.');
        }

        $challenge = $this->stringParam($params, 'code_challenge');
        $method    = $this->stringParam($params, 'code_challenge_method');
        if ($challenge === null || $method !== 'S256' || !PkceVerifier::isValidChallenge($challenge)) {
            return $this->redirectError($redirectUri, $state, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $rawScope = $params['scope'] ?? null;
        $maxScope = OAuthScope::maxLevel(is_string($rawScope) ? $rawScope : null);
        if ($maxScope === null || ($rawScope !== null && !is_string($rawScope))) {
            return $this->redirectError($redirectUri, $state, 'invalid_scope', 'Supported scopes: read, write, moderate.');
        }

        $resource = $params['resource'] ?? null;
        if ($resource !== null && (!is_string($resource) || !$this->metadata->acceptsResource($resource))) {
            return $this->redirectError($redirectUri, $state, 'invalid_target', 'The requested resource is not served by this authorization server.');
        }

        return new AuthorizationRequest($client, $redirectUri, $challenge, $state, $maxScope, $this->metadata->issuer());
    }

    private function redirectError(string $redirectUri, ?string $state, string $error, string $description): AuthorizationError
    {
        return new AuthorizationError(
            $error,
            $description,
            AuthorizationRequest::errorRedirectFor($redirectUri, $state, $this->metadata->issuer(), $error, $description),
        );
    }

    /** @param array<array-key, mixed> $params */
    private function stringParam(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
