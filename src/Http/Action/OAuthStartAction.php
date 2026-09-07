<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\OAuthStateRepository;
use Votepit\Security\OAuth\OAuthProviderRegistry;
use Votepit\Security\OAuth\PkceGenerator;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\TokenVault;

/**
 * GET /login/oauth/{provider}/start — begins the OAuth2 authorization-code
 * flow for `google`|`github` (AuthZ: anon; additive to magic-link/password/
 * TOTP, never a replacement — see LoginPasswordAction class doc for the
 * same "additive" framing).
 *
 * Fail-secure by construction (requirement: an unconfigured/unknown provider
 * button must not even be reachable if hit directly): an unknown provider
 * key (not in OAuthProviderRegistry::PROVIDERS) or one without BOTH
 * client_id/client_secret configured (Config::oauthProviderConfigured())
 * both 404 identically — no distinction is leaked between "unknown" and
 * "known but unconfigured".
 *
 * `state` is minted here (TokenVault — same crypto as login_tokens), stored
 * SERVER-SIDE (OAuthStateRepository, hash only) together with the validated
 * `returnTo` and, for providers that support it (Google), a PKCE
 * code_verifier — never trusted from a client-supplied cookie. `redirect_uri`
 * is built exclusively from Config::appUrl + a hard-coded path, never from
 * request input (open-redirect / redirect_uri-confusion prevention).
 */
final readonly class OAuthStartAction
{
    /** TTL of a minted state — long enough for a real provider consent flow, short enough to keep the replay window tight. */
    private const STATE_TTL_SECONDS = 600;

    public function __construct(
        private Config $config,
        private OAuthStateRepository $states,
        private TokenVault $vault,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $provider = is_string($args['provider'] ?? null) ? $args['provider'] : '';

        if (!OAuthProviderRegistry::isKnown($provider) || !$this->config->oauthProviderConfigured($provider)) {
            return $this->notFound($response);
        }

        $endpoints = OAuthProviderRegistry::endpoints($provider);
        $creds     = $this->config->oauthProvider($provider);

        $params   = $request->getQueryParams();
        $rawR     = is_string($params['r'] ?? null) ? $params['r'] : '';
        $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : null;

        $statePair    = $this->vault->generate();
        $codeVerifier = null;
        $authParams   = [
            'response_type' => 'code',
            'client_id'     => $creds['client_id'],
            // Hard-coded path, never derived from request input — the ONLY
            // valid target this app will ever exchange a code for (pinned
            // against open-redirect / redirect_uri confusion). Must match
            // the URI registered in the provider's console exactly.
            'redirect_uri'  => $this->config->appUrl . '/login/oauth/' . $provider . '/callback',
            'scope'         => $endpoints['scope'],
            'state'         => $statePair['token'],
        ];

        if ($endpoints['pkce']) {
            $pkce                    = PkceGenerator::generate();
            $codeVerifier            = $pkce['verifier'];
            $authParams['code_challenge']        = $pkce['challenge'];
            $authParams['code_challenge_method'] = 'S256';
        }

        $expiresAt = (new \DateTimeImmutable('+' . self::STATE_TTL_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
        $this->states->insert($statePair['hash'], $provider, $codeVerifier, $returnTo, $expiresAt);

        $this->audit->log('oauth.start', ['provider' => $provider]);

        return $response
            ->withStatus(302)
            ->withHeader('Location', $endpoints['authorize'] . '?' . http_build_query($authParams));
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'not_found', 'message' => 'Unknown or unconfigured provider.'],
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
